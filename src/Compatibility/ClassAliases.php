<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

/**
 * Former public names of classes moved into responsibility namespaces.
 *
 * A former name becomes an alias as soon as either name is autoloaded, so a
 * parameter or return type declared with a former name accepts the relocated
 * class while no implementation is loaded during Composer bootstrap.
 *
 * @var array<class-string, string>
 */
$formerNames = [
    ZtdQuery\Platform\Sqlite\Connection\Parameter\SqlitePdoParameterBindingCompiler::class => 'ZtdQuery\\Platform\\Sqlite\\SqlitePdoParameterBindingCompiler',
    ZtdQuery\Platform\Sqlite\Connection\Result\SqlitePdoResultColumnTypeResolver::class => 'ZtdQuery\\Platform\\Sqlite\\SqlitePdoResultColumnTypeResolver',
    ZtdQuery\Platform\Sqlite\Connection\SqliteErrorClassifier::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteErrorClassifier',
    ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteCteShadowComposer',
    ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteFullTextSearchRewriter',
    ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteGeneratedColumnProjector',
    ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteIndexHintStripper',
    ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteQueryGuard',
    ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteRewriter',
    ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer::class => 'ZtdQuery\\Platform\\Sqlite\\Transformer\\DeleteTransformer',
    ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer::class => 'ZtdQuery\\Platform\\Sqlite\\Transformer\\InsertRowRenderer',
    ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer::class => 'ZtdQuery\\Platform\\Sqlite\\Transformer\\InsertSelectRenderer',
    ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer::class => 'ZtdQuery\\Platform\\Sqlite\\Transformer\\InsertTransformer',
    ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer::class => 'ZtdQuery\\Platform\\Sqlite\\Transformer\\SelectTransformer',
    ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer::class => 'ZtdQuery\\Platform\\Sqlite\\Transformer\\SqliteTransformer',
    ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer::class => 'ZtdQuery\\Platform\\Sqlite\\Transformer\\UpdateTransformer',
    ZtdQuery\Platform\Sqlite\Rewrite\Upsert\SqliteNativeUpsertProjector::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteNativeUpsertProjector',
    ZtdQuery\Platform\Sqlite\Rewrite\View\SqliteViewShadowRenderer::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteViewShadowRenderer',
    ZtdQuery\Platform\Sqlite\Schema\Key\SqliteForeignKeyDefinitionParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteForeignKeyDefinitionParser',
    ZtdQuery\Platform\Sqlite\Schema\SqliteColumnTypeMapper::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteColumnTypeMapper',
    ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteSchemaParser',
    ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaReflector::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteSchemaReflector',
    ZtdQuery\Platform\Sqlite\Schema\View\SqliteViewDefinitionParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteViewDefinitionParser',
    ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation::class => 'ZtdQuery\\Platform\\Sqlite\\Mutation\\AlterTableMutation',
    ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\SqliteUpsertExpressionParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteUpsertExpressionParser',
    ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteMutationResolver',
    ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteInMemoryAttachStatement',
    ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteReadOnlyDiagnosticStatement',
    ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteSelectRelationParser',
    ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteReturningProjectionParser',
    ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteIdentifierQuoter',
    ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteLexerProfile',
    ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteLexicalMasker',
    ZtdQuery\Platform\Sqlite\Sql\SqliteParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteParser',
    ZtdQuery\Platform\Sqlite\Sql\Transaction\SqliteTransactionStatementParser::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteTransactionStatementParser',
    ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteCastRenderer',
    ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer::class => 'ZtdQuery\\Platform\\Sqlite\\SqliteValueRenderer',
];
$currentNames = array_flip($formerNames);

spl_autoload_register(static function (string $class) use ($formerNames, $currentNames): void {
    $current = $currentNames[$class] ?? $class;
    $former = $formerNames[$current] ?? null;
    if ($former === null) {
        return;
    }
    if (!class_exists($current, false)) {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            if ($loader->loadClass($current) === true) {
                break;
            }
        }
    }
    if (class_exists($current, false) && !class_exists($former, false)) {
        class_alias($current, $former);
    }
}, true, true);
