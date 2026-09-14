<?php

declare(strict_types=1);

namespace Fuzz\Semantics;

use Error;
use PDO;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;
use ZtdQuery\Rewrite\QueryKind;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Compares rewritten SELECT and simulated DML with native SQLite after every operation.
 */
final class SemanticsTarget
{
    /**
     * Retains SQLFaker's grammar analysis between independent command sequences.
     */
    public function __construct(private readonly CommandSequence $sequence)
    {
    }

    /**
     * Runs one bounded sequence; local connections and all mutable state expire on return or failure.
     * @throws Error
     */
    public function __invoke(string $input): void
    {
        $commands = $this->sequence->compile($input);
        $schema = 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score INTEGER NOT NULL)';
        $nativeDatabase = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $physicalDatabase = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $nativeDatabase->exec($schema);
        $physicalDatabase->exec($schema);
        $nativeDatabase->exec("INSERT INTO users VALUES (1, 'Alice', 10), (2, 'Bob', 20)");
        $physicalDatabase->exec("INSERT INTO users VALUES (9000, 'physical', 777)");
        $definition = (new SqliteSchemaParser())->parse($schema);
        if ($definition === null) {
            throw new Error('Fixture schema could not be reflected');
        }
        $registry = new TableDefinitionRegistry();
        $registry->register('users', $definition);
        $store = new ShadowStore();
        $store->set('users', [['id' => 1, 'name' => 'Alice', 'score' => 10], ['id' => 2, 'name' => 'Bob', 'score' => 20]]);
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $castRenderer = new SqliteCastRenderer();
        $quoter = new SqliteIdentifierQuoter();
        $selectTransformer = new SelectTransformer($castRenderer, $quoter);
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $schemaParser = new SqliteSchemaParser();
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        foreach ($commands as $sql) {
            $native = StateComparison::query($nativeDatabase, $sql);
            $plan = $rewriter->rewrite($sql);
            $rows = StateComparison::query($physicalDatabase, $plan->sql());
            if ($plan->kind() === QueryKind::READ && $rows !== $native) {
                throw new Error('Native/rewritten result mismatch: ' . var_export([$native, $rows], true));
            }
            $plan->mutation()?->apply($store, $rows);
            $rewriter->commitRewriteState();
            StateComparison::compare($nativeDatabase, $physicalDatabase, $store);
        }
    }
}
