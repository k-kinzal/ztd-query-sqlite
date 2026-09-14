<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert;

use ZtdQuery\Shadow\Mutation\UpsertExpression;
use ZtdQuery\Shadow\Mutation\UpsertExpressionKind;

/**
 * Parses boolean conjunctions and disjunctions in upsert expressions.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class LogicalExpressionParser
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(private ExpressionCursor $cursor)
    {
    }

    /**
     * Parses boolean conjunctions and disjunctions in upsert expressions.
     */
    public function parseOr(): UpsertExpression
    {
        $left = $this->parseAnd();
        while (($this->cursor->tokens[$this->cursor->index] ?? null)?->isKeyword('OR') === true) {
            $this->cursor->index++;
            $left = UpsertExpression::binary(UpsertExpressionKind::Or, $left, $this->parseAnd());
        }

        return $left;
    }

    /**
     * Parses boolean conjunctions and disjunctions in upsert expressions.
     */
    public function parseAnd(): UpsertExpression
    {
        $left = (new ComparisonExpressionParser($this->cursor))->parseComparison();
        while (($this->cursor->tokens[$this->cursor->index] ?? null)?->isKeyword('AND') === true) {
            $this->cursor->index++;
            $left = UpsertExpression::binary(UpsertExpressionKind::And, $left, (new ComparisonExpressionParser($this->cursor))->parseComparison());
        }

        return $left;
    }
}
