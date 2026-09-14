<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\FullText;

use ZtdQuery\Sql\SqlTokenStream;

/**
 * Replaces FTS virtual-table operators with expressions executable over shadow CTE rows.
 *
 * @phpstan-type TableContext array{viewSql: string}|array{columns: array<int, string>}
 */
final class SqliteFullTextSearchRewriter
{
    private \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter $quoter;
    private \ZtdQuery\Platform\Sqlite\Sql\SqliteParser $parser;

    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct()
    {
        $this->quoter = new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter();
        $this->parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
    }

    /**
     * @param array<string, TableContext> $tables
     */
    public function rewrite(string $sql, array $tables): string
    {
        $stream = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create());
        /**
         * @var list<array{start: int, end: int, replacement: string}> $edits
         */
        $edits = [];

        foreach ($stream->significantTokens() as $operator) {
            $edit = (new MatchExpressionRewriter($this->parser, $this->quoter))->expressionEdit($stream, $operator, $tables);
            if ($edit === null) {
                continue;
            }
            $edits[] = $edit;
        }

        usort($edits, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($edits as $edit) {
            $sql = substr_replace($sql, $edit['replacement'], $edit['start'], $edit['end'] - $edit['start']);
        }

        return $sql;
    }

}
