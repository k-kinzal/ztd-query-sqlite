<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Config\ZtdConfig;
use ZtdQuery\Connection\ConnectionInterface;
use ZtdQuery\Platform\SessionFactory;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\ResultSelectRunner;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinitionSet;
use ZtdQuery\Session;
use ZtdQuery\Shadow\ShadowStore;
use ZtdQuery\Shadow\ShadowTransactions;

/**
 * Factory for creating Session instances pre-configured for SQLite.
 * @visibility public
 * @example Create a session for a custom connection
 *     $connection = new class implements \ZtdQuery\Connection\ConnectionInterface { public function query(string $sql): \ZtdQuery\Connection\StatementInterface|false { return false; } };
 *     $session = (new \ZtdQuery\Platform\Sqlite\SqliteSessionFactory())->create($connection, new \ZtdQuery\Config\ZtdConfig());
 *     $session->rewrite("SELECT 1")->sql() // => 'SELECT 1'
 */
final class SqliteSessionFactory implements SessionFactory
{
    /**
     * {@inheritDoc}
     *
     * @visibility public
     * @example Create a session when the connection has no reflected tables
     *     $connection = new class implements \ZtdQuery\Connection\ConnectionInterface { public function query(string $sql): \ZtdQuery\Connection\StatementInterface|false { return false; } };
     *     $session = (new \ZtdQuery\Platform\Sqlite\SqliteSessionFactory())->create($connection, new \ZtdQuery\Config\ZtdConfig());
     *     $session->isEnabled() // => true
     *     $session->rewrite('SELECT 1')->sql() // => 'SELECT 1'
     */
    public function create(ConnectionInterface $connection, ZtdConfig $config): Session
    {
        $shadowStore = new ShadowStore();
        $parser = new Sql\SqliteParser();
        $schemaParser = new Schema\SqliteSchemaParser();
        $registry = new TableDefinitionRegistry();

        $reflector = new Schema\SqliteSchemaReflector($connection);
        foreach ($reflector->reflectAll() as $tableName => $createSql) {
            $definition = $schemaParser->parse($createSql);
            if ($definition !== null) {
                $registry->register($tableName, $definition);
            }
        }
        $views = new ViewDefinitionSet();
        foreach ($reflector->reflectViews() as $viewName => $definition) {
            $views->register($viewName, $definition);
        }

        $guard = new Rewrite\SqliteQueryGuard($parser);
        $selectTransformer = new SelectTransformer();
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $mutationResolver = new Shadow\SqliteMutationResolver($shadowStore, $registry, $schemaParser, $parser);
        $rewriter = new Rewrite\SqliteRewriter($guard, $shadowStore, $registry, $transformer, $mutationResolver, $parser, $views);

        return new Session(
            $rewriter,
            $shadowStore,
            new ResultSelectRunner(),
            $config,
            $connection,
            transactions: new ShadowTransactions($shadowStore, $registry),
            registry: $registry,
            parameterBindingCompiler: new Connection\Parameter\SqlitePdoParameterBindingCompiler(),
            resultColumnTypeResolver: new Connection\Result\SqlitePdoResultColumnTypeResolver(),
        );
    }
}
