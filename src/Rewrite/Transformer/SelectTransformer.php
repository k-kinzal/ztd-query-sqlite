<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Transformer;

use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\IdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Rewrite\Cte\SqliteCteShadowComposer;
use ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter;
use ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector;
use ZtdQuery\Platform\Sqlite\Rewrite\Index\SqliteIndexHintStripper;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;
use ZtdQuery\Platform\ValueRenderer;
use ZtdQuery\Rewrite\SqlTransformer;

/**
 * Applies CTE shadowing to SELECT statements for SQLite.
 *
 * Generates WITH clauses that shadow referenced tables using in-memory data.
 * Uses double-quote identifiers and SQLite-compatible CAST types.
 * @visibility public
 * @example Transform a table-free query
 *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
 *     $transformer->transform("SELECT 1", []) // => 'SELECT 1'
 */
final class SelectTransformer implements SqlTransformer
{
    private CastRenderer $castRenderer;
    private IdentifierQuoter $quoter;
    private ValueRenderer $valueRenderer;
    private SqliteCteShadowComposer $cteComposer;
    private SqliteIndexHintStripper $indexHintStripper;
    private SqliteGeneratedColumnProjector $generatedColumnProjector;
    private SqliteFullTextSearchRewriter $fullTextSearchRewriter;

    /**
     * Binds the dependencies used by this operation.
     * @visibility public
     * @example Use the SQLite renderers by default
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer->transform("SELECT 1", []) // => 'SELECT 1'
     */
    public function __construct(
        ?CastRenderer $castRenderer = null,
        ?IdentifierQuoter $quoter = null,
        ?ValueRenderer $valueRenderer = null,
    ) {
        $this->castRenderer = $castRenderer ?? new SqliteCastRenderer();
        $this->quoter = $quoter ?? new SqliteIdentifierQuoter();
        $this->valueRenderer = $valueRenderer ?? new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer($this->castRenderer);
        $this->cteComposer = new SqliteCteShadowComposer();
        $this->indexHintStripper = new SqliteIndexHintStripper();
        $this->generatedColumnProjector = new SqliteGeneratedColumnProjector();
        $this->fullTextSearchRewriter = new SqliteFullTextSearchRewriter();
    }

    /**
     * {@inheritDoc}
     *
     * @visibility public
     * @example Read fixture rows through SQLite CTE shadowing
     *     $tables = ['users' => ['columns' => ['id', 'name'], 'columnTypes' => [], 'rows' => [['id' => 1, 'name' => 'Alice']]]];
     *     $sql = (new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer())->transform('SELECT name FROM users', $tables);
     *     $pdo = new \PDO('sqlite::memory:');
     *     $pdo->query($sql)->fetchColumn() // => 'Alice'
     */
    public function transform(string $sql, array $tables): string
    {
        $sql = $this->fullTextSearchRewriter->rewrite($sql, $tables);
        $ctes = [];
        foreach ($tables as $tableName => $tableContext) {
            if (isset($tableContext['viewSql'])) {
                $ctes[$tableName] = $this->quoter->quote($tableName) . " AS ({$tableContext['viewSql']})";
                continue;
            }

            $rows = $tableContext['rows'];
            $columns = $tableContext['columns'];
            $columnTypes = $tableContext['columnTypes'];
            $generatedExpressions = $tableContext['generatedExpressions'] ?? [];

            if ($columns === [] && $rows !== []) {
                $columns = array_keys($rows[0]);
                foreach ($rows as $row) {
                    foreach (array_keys($row) as $column) {
                        if (!in_array($column, $columns, true)) {
                            $columns[] = $column;
                        }
                    }
                }
            }

            if ($columns === [] && $rows === []) {
                continue;
            }

            $ctes[$tableName] = (new Select\ShadowCteRenderer($this->castRenderer, $this->generatedColumnProjector, $this->quoter, $this->valueRenderer))->generateCte(
                $tableName,
                $rows,
                $columns,
                $columnTypes,
                $generatedExpressions,
            );
        }

        $sql = $this->indexHintStripper->strip($sql, array_keys($ctes));

        return $this->cteComposer->compose($sql, $ctes);
    }

}
