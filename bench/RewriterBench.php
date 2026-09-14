<?php

declare(strict_types=1);

namespace Bench;

use PhpBench\Attributes as Benchmark;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Measures fixed rewrite workloads with construction outside the timed subjects.
 */
final class RewriterBench
{
    private SqliteRewriter $rewriter;

    private string $selectSql = 'SELECT id, name, email FROM users WHERE id = 1';

    private string $insertSql = "INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')";

    /**
     * Recreates dependencies before each benchmark iteration.
     */
    public function setUp(): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $registry->register(
            'users',
            new TableDefinition(
                ['id', 'name', 'email'],
                ['id' => 'INTEGER', 'name' => 'TEXT', 'email' => 'TEXT'],
                ['id'],
                ['id', 'name'],
                [],
            ),
        );

        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer(
            $parser,
            $selectTransformer,
            $insertTransformer,
            $updateTransformer,
            $deleteTransformer,
        );
        $schemaParser = new SqliteSchemaParser();
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);

        $this->rewriter = new SqliteRewriter(
            new SqliteQueryGuard($parser),
            $store,
            $registry,
            $transformer,
            $mutationResolver,
            $parser,
        );
    }

    /**
     * Measures one operation over the fixed SQL fixture.
     */
    #[Benchmark\BeforeMethods('setUp')]
    #[Benchmark\Revs(100)]
    public function benchRewriteSelect(): \ZtdQuery\Rewrite\RewritePlan
    {
        return $this->rewriter->rewrite($this->selectSql);
    }

    /**
     * Measures one operation over the fixed SQL fixture.
     */
    #[Benchmark\BeforeMethods('setUp')]
    #[Benchmark\Revs(100)]
    public function benchRewriteInsert(): \ZtdQuery\Rewrite\RewritePlan
    {
        return $this->rewriter->rewrite($this->insertSql);
    }
}
