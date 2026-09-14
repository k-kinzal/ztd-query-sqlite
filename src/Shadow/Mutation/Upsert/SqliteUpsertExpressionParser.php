<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Shadow\Mutation\UpsertExpression;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Parses supported upsert expressions with SQLite operator precedence.
 */
final class SqliteUpsertExpressionParser
{
    /**
     * Parses the supplied SQL into its supported structured representation.
     * @throws UnsupportedSqlException
     */
    public function parse(string $sql, string $tableName): UpsertExpression
    {
        $cursor = new ExpressionCursor(
            $sql,
            $tableName,
            SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens(),
        );
        $expression = (new LogicalExpressionParser($cursor))->parseOr();
        if ($cursor->index !== count($cursor->tokens)) {
            throw (new ExpressionTokenDecoder($sql))->unsupported();
        }

        return $expression;
    }

    /**
     * Returns the parsed expression, or null for unsupported SQLite syntax.
     */
    public function parseIfSupported(string $sql, string $tableName): ?UpsertExpression
    {
        try {
            return $this->parse($sql, $tableName);
        } catch (UnsupportedSqlException) {
            return null;
        }
    }

}
