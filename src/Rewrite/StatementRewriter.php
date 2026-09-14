<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite;

use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Context\ReferencedTableLookup;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer;
use ZtdQuery\Platform\Sqlite\Rewrite\Returning\SqliteReturningProjectionParser;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\AlterTableMutation;
use ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\Attach\SqliteInMemoryAttachStatement;
use ZtdQuery\Platform\Sqlite\Sql\Diagnostic\SqliteReadOnlyDiagnosticStatement;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Rewrite\AffectedRowsMode;
use ZtdQuery\Rewrite\QueryKind;
use ZtdQuery\Rewrite\RewritePlan;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinitionSet;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Builds the read projection and mutation plan for one SQLite statement.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class StatementRewriter
{
    private ReferencedTableLookup $tableLookup;

    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        SqliteCteShadowComposer $cteComposer,
        private SqliteQueryGuard $guard,
        private SqliteMutationResolver $mutationResolver,
        SqliteParser $parser,
        private TableDefinitionRegistry $registry,
        private SqliteReturningProjectionParser $returningProjectionParser,
        private ShadowStore $shadowStore,
        private SqliteTransformer $transformer,
        private ViewDefinitionSet $views
    ) {
        $this->tableLookup = new ReferencedTableLookup($cteComposer, $parser, $registry, $shadowStore, $views);
    }

    /**
     * Builds the read projection and mutation plan for one SQLite statement.
     * @throws UnknownSchemaException
     * @throws UnsupportedSqlException
     */
    public function rewriteStatement(string $stmtSql, string $originalSql): RewritePlan
    {
        if (SqliteInMemoryAttachStatement::isSafe($stmtSql) || SqliteReadOnlyDiagnosticStatement::isSafe($stmtSql)) {
            return new RewritePlan($stmtSql, QueryKind::READ);
        }
        $kind = $this->guard->classify($stmtSql);
        if ($kind === null) {
            throw new UnsupportedSqlException($originalSql, 'Statement type not supported');
        }

        $tableContext = (new Context\TableContextBuilder($this->registry, $this->shadowStore->getAll(), $this->views))->buildTableContext();

        if ($kind === QueryKind::READ) {
            $this->tableLookup->assertKnownTables($stmtSql, $originalSql);

            $transformedSql = $this->transformer->transform($stmtSql, $tableContext);

            return new RewritePlan($transformedSql, QueryKind::READ);
        }

        if ($kind === QueryKind::DDL_SIMULATED) {
            $mutation = $this->mutationResolver->resolve($stmtSql, $kind);
            if ($mutation instanceof AlterTableMutation) {
                return new RewritePlan(
                    $this->transformer->transform($mutation->resultSelect(), $tableContext),
                    QueryKind::DDL_SIMULATED,
                    $mutation,
                    affectedRowsMode: AffectedRowsMode::None,
                );
            }

            return new RewritePlan('SELECT 1 WHERE 0', QueryKind::DDL_SIMULATED, $mutation);
        }

        $mutation = $this->mutationResolver->resolve($stmtSql, $kind);

        $transformedSql = $this->transformer->transform($stmtSql, $tableContext);

        return new RewritePlan(
            $transformedSql,
            QueryKind::WRITE_SIMULATED,
            $mutation,
            $this->returningProjectionParser->parse($stmtSql),
            AffectedRowsMode::Matched,
        );
    }
}
