<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Returning;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Sql\Returning\ReturningItemParser;
use ZtdQuery\Rewrite\ReturningProjection;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Extracts RETURNING expressions and their output column names.
 */
final class SqliteReturningProjectionParser
{
    /**
     * Parses the supplied SQL into its supported structured representation.
     * @throws UnsupportedSqlException
     */
    public function parse(string $sql): ?ReturningProjection
    {
        $clause = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClause(['RETURNING']);
        if ($clause === null) {
            return null;
        }

        $items = [];
        foreach (SqlTokenStream::tokenize(rtrim($clause, "; \t\n\r\0\x0B"), \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->splitTopLevel() as $expression) {
            $item = (new ReturningItemParser())->parseItem($expression);
            if ($item === null) {
                throw new UnsupportedSqlException(
                    $sql,
                    'RETURNING supports columns, qualified columns, aliases, and wildcard projections',
                );
            }
            $items[] = $item;
        }

        if ($items === []) {
            throw new UnsupportedSqlException($sql, 'RETURNING requires a projection');
        }

        return ReturningProjection::fromItems($items);
    }

}
