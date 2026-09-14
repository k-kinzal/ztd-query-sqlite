<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert;

use ZtdQuery\Sql\SqlToken;

/**
 * Shares the token position across precedence levels of one expression parse.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ExpressionCursor
{
    /**
     * Index of the next unconsumed significant token.
     */
    public int $index = 0;

    /**
     * @param list<SqlToken> $tokens Significant tokens in the original SQL.
     */
    public function __construct(
        public readonly string $sql,
        public readonly string $tableName,
        public readonly array $tokens,
    ) {
    }
}
