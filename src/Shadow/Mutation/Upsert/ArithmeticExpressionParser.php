<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert;

use ZtdQuery\Shadow\Mutation\UpsertExpression;
use ZtdQuery\Shadow\Mutation\UpsertExpressionKind;

/**
 * Parses arithmetic operators using SQLite precedence.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ArithmeticExpressionParser
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(private ExpressionCursor $cursor)
    {
    }

    /**
     * Parses arithmetic operators using SQLite precedence.
     */
    public function parseAdditive(): UpsertExpression
    {
        $left = $this->parseMultiplicative();
        while (isset($this->cursor->tokens[$this->cursor->index]) && (new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($this->cursor->tokens[$this->cursor->index], ['+', '-'])) {
            $operator = $this->cursor->tokens[$this->cursor->index]->text;
            $this->cursor->index++;
            $left = UpsertExpression::binary(
                $operator === '+' ? UpsertExpressionKind::Add : UpsertExpressionKind::Subtract,
                $left,
                $this->parseMultiplicative(),
            );
        }

        return $left;
    }

    /**
     * Parses arithmetic operators using SQLite precedence.
     */
    public function parseMultiplicative(): UpsertExpression
    {
        $left = $this->parseUnary();
        while (isset($this->cursor->tokens[$this->cursor->index])
            && (new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($this->cursor->tokens[$this->cursor->index], ['*', '/', '%'])
        ) {
            $operator = $this->cursor->tokens[$this->cursor->index]->text;
            $this->cursor->index++;
            $kind = $operator === '*'
                ? UpsertExpressionKind::Multiply
                : ($operator === '/' ? UpsertExpressionKind::Divide : UpsertExpressionKind::Modulo);
            $left = UpsertExpression::binary($kind, $left, $this->parseUnary());
        }

        return $left;
    }

    /**
     * Parses arithmetic operators using SQLite precedence.
     */
    public function parseUnary(): UpsertExpression
    {
        $token = $this->cursor->tokens[$this->cursor->index] ?? null;
        if ($token?->isKeyword('NOT') === true) {
            $this->cursor->index++;

            return UpsertExpression::unary(UpsertExpressionKind::Not, $this->parseUnary());
        }
        if ($token !== null && (new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($token, ['+', '-'])) {
            $this->cursor->index++;

            return UpsertExpression::unary(
                $token->text === '+' ? UpsertExpressionKind::UnaryPlus : UpsertExpressionKind::UnaryMinus,
                $this->parseUnary(),
            );
        }

        return (new PrimaryExpressionParser($this->cursor))->parsePrimary();
    }
}
