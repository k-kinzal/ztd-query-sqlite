<?php

declare(strict_types=1);

namespace Bench;

use ZtdQuery\Platform\Sqlite\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\UpdateTransformer;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

final class RewriterBench
{
    private SqliteRewriter $rewriter;

    private string $selectSql = 'SELECT id, name, email FROM users WHERE id = 1';

    private string $insertSql = "INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')";

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
     * @BeforeMethods({"setUp"})
     * @Revs(100)
     * @Iterations(5)
     */
    public function benchRewriteSelect(): void
    {
        $this->rewriter->rewrite($this->selectSql);
    }

    /**
     * @BeforeMethods({"setUp"})
     * @Revs(100)
     * @Iterations(5)
     */
    public function benchRewriteInsert(): void
    {
        $this->rewriter->rewrite($this->insertSql);
    }
}
