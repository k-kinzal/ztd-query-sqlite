<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Config\ZtdConfig;
use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Platform\Sqlite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\UpdateTransformer;
use ZtdQuery\ResultSelectRunner;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Session;
use ZtdQuery\Platform\SessionFactory;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Factory for creating Session instances pre-configured for SQLite.
 */
final class SqliteSessionFactory implements SessionFactory
{
    /**
     * {@inheritDoc}
     */
    public function create(ConnectionInterface $connection, ZtdConfig $config): Session
    {
        $shadowStore = new ShadowStore();
        $parser = new SqliteParser();
        $schemaParser = new SqliteSchemaParser();
        $registry = new TableDefinitionRegistry();

        $reflector = new SqliteSchemaReflector($connection);
        foreach ($reflector->reflectAll() as $tableName => $createSql) {
            $definition = $schemaParser->parse($createSql);
            if ($definition !== null) {
                $registry->register($tableName, $definition);
            }
        }

        $guard = new SqliteQueryGuard($parser);
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new SqliteMutationResolver($shadowStore, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $shadowStore, $registry, $transformer, $mutationResolver, $parser);

        return new Session(
            $rewriter,
            $shadowStore,
            new ResultSelectRunner(),
            $config,
            $connection
        );
    }
}
