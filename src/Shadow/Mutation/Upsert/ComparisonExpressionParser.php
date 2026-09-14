<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert;

use ZtdQuery\Shadow\Mutation\UpsertExpression;
use ZtdQuery\Shadow\Mutation\UpsertExpressionKind;

/**
 * Parses relational operators in upsert predicates.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ComparisonExpressionParser
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(private ExpressionCursor $cursor)
    {
    }

    /**
     * Parses relational operators in upsert predicates.
     */
    public function parseComparison(): UpsertExpression
    {
        $left = (new ArithmeticExpressionParser($this->cursor))->parseAdditive();
        $operator = $this->comparisonOperator();

        return $operator === null
            ? $left
            : UpsertExpression::binary($operator, $left, (new ArithmeticExpressionParser($this->cursor))->parseAdditive());
    }

    /**
     * Parses relational operators in upsert predicates.
     * @throws \ZtdQuery\Exception\UnsupportedSqlException
     */
    public function comparisonOperator(): ?UpsertExpressionKind
    {
        $first = $this->cursor->tokens[$this->cursor->index] ?? null;
        if ($first === null || !(new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($first, ['=', '!', '<', '>'])) {
            return null;
        }
        $operator = $first->text;
        $second = $this->cursor->tokens[$this->cursor->index + 1] ?? null;
        if ($second !== null && (new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($second, ['=', '>']) && $operator !== '=') {
            $operator .= $second->text;
            $this->cursor->index++;
        }
        $this->cursor->index++;

        return match ($operator) {
            '=' => UpsertExpressionKind::Equal,
            '!=', '<>' => UpsertExpressionKind::NotEqual,
            '<' => UpsertExpressionKind::Less,
            '<=' => UpsertExpressionKind::LessOrEqual,
            '>' => UpsertExpressionKind::Greater,
            '>=' => UpsertExpressionKind::GreaterOrEqual,
            default => throw (new ExpressionTokenDecoder($this->cursor->sql))->unsupported(),
        };
    }
}
