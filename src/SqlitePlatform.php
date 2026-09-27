<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Platform;
use ZtdQuery\Platform\CopySupport;
use ZtdQuery\Platform\ParameterBindingCompiler;
use ZtdQuery\Platform\ResultColumnTypeResolver;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Rewrite\SqlRewriter;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinitionSet;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Database semantics for SQLite.
 *
 * Inject this platform into a QueryExecutor or a driver adapter. Each executor owns
 * its session state and receives an independent rewrite pipeline.
 * @visibility public
 * @example Inject database semantics without creating a session
 *     $platform = new \ZtdQuery\Platform\Sqlite\SqlitePlatform();
 *     $platform instanceof \ZtdQuery\Platform // => true
 */
final class SqlitePlatform implements Platform
{
    /**
     * {@inheritDoc}
     */
    public function reflectSchema(ConnectionInterface $connection): TableDefinitionRegistry
    {
        $schemaParser = new Schema\SqliteSchemaParser();
        $registry = new TableDefinitionRegistry();

        $reflector = new Schema\SqliteSchemaReflector($connection);
        foreach ($reflector->reflectAll() as $tableName => $createSql) {
            $definition = $schemaParser->parse($createSql);
            if ($definition !== null) {
                $registry->register($tableName, $definition);
            }
        }

        return $registry;
    }

    /**
     * {@inheritDoc}
     */
    public function reflectViews(ConnectionInterface $connection): ViewDefinitionSet
    {
        $reflector = new Schema\SqliteSchemaReflector($connection);
        $views = new ViewDefinitionSet();
        foreach ($reflector->reflectViews() as $viewName => $definition) {
            $views->register($viewName, $definition);
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function createRewriter(ShadowStore $store, TableDefinitionRegistry $registry, ViewDefinitionSet $views): SqlRewriter
    {
        $parser = new Sql\SqliteParser();
        $schemaParser = new Schema\SqliteSchemaParser();

        $guard = new Rewrite\SqliteQueryGuard($parser);
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new Shadow\SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        return new Rewrite\SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser, $views);
    }

    /**
     * {@inheritDoc}
     */
    public function copySupport(): ?CopySupport
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function parameterBindingCompiler(): ParameterBindingCompiler
    {
        return new Connection\Parameter\SqlitePdoParameterBindingCompiler();
    }

    /**
     * {@inheritDoc}
     */
    public function resultColumnTypeResolver(): ResultColumnTypeResolver
    {
        return new Connection\Result\SqlitePdoResultColumnTypeResolver();
    }
}
