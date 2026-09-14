<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert;

use ZtdQuery\Shadow\Mutation\UpsertColumnSource;
use ZtdQuery\Shadow\Mutation\UpsertExpression;
use ZtdQuery\Sql\SqlTokenKind;

/**
 * Parses parenthesized values and column references in upsert expressions.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class PrimaryExpressionParser
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(private ExpressionCursor $cursor)
    {
    }

    /**
     * Parses parenthesized values and column references in upsert expressions.
     * @throws \ZtdQuery\Exception\UnsupportedSqlException
     */
    public function parsePrimary(): UpsertExpression
    {
        $token = $this->cursor->tokens[$this->cursor->index] ?? null;
        if ($token === null) {
            throw (new ExpressionTokenDecoder($this->cursor->sql))->unsupported();
        }
        if ((new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($token, ['('])) {
            $this->cursor->index++;
            $expression = (new LogicalExpressionParser($this->cursor))->parseOr();
            if (!isset($this->cursor->tokens[$this->cursor->index]) || !(new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($this->cursor->tokens[$this->cursor->index], [')'])) {
                throw (new ExpressionTokenDecoder($this->cursor->sql))->unsupported();
            }
            $this->cursor->index++;

            return $expression;
        }
        if ($token->kind === SqlTokenKind::Number) {
            $this->cursor->index++;

            return UpsertExpression::literal((new ExpressionTokenDecoder($this->cursor->sql))->number($token->text));
        }
        if ($token->kind === SqlTokenKind::String) {
            $this->cursor->index++;

            return UpsertExpression::literal((new ExpressionTokenDecoder($this->cursor->sql))->string($token->text));
        }
        if ($token->isKeyword('NULL')) {
            $this->cursor->index++;

            return UpsertExpression::literal(null);
        }
        if ($token->isKeyword('TRUE') || $token->isKeyword('FALSE')) {
            $this->cursor->index++;

            return UpsertExpression::literal($token->isKeyword('TRUE'));
        }
        return $this->parseColumn();
    }

    /**
     * Parses parenthesized values and column references in upsert expressions.
     * @throws \ZtdQuery\Exception\UnsupportedSqlException
     */
    public function columnSource(string $qualifier): UpsertColumnSource
    {
        if (strcasecmp($qualifier, 'EXCLUDED') === 0) {
            return UpsertColumnSource::Incoming;
        }
        if (strcasecmp($qualifier, $this->cursor->tableName) === 0) {
            return UpsertColumnSource::Existing;
        }

        throw (new ExpressionTokenDecoder($this->cursor->sql))->unsupported();
    }
    /**
     * Consumes an existing or EXCLUDED column reference.
     *
     * @throws \ZtdQuery\Exception\UnsupportedSqlException
     */
    public function parseColumn(): UpsertExpression
    {
        $token = $this->cursor->tokens[$this->cursor->index] ?? null;
        if ($token === null) {
            throw (new ExpressionTokenDecoder($this->cursor->sql))->unsupported();
        }
        if (!(new ExpressionTokenDecoder($this->cursor->sql))->isIdentifier($token)) {
            throw (new ExpressionTokenDecoder($this->cursor->sql))->unsupported();
        }

        $identifier = (new ExpressionTokenDecoder($this->cursor->sql))->identifier($token);
        $this->cursor->index++;
        if (isset($this->cursor->tokens[$this->cursor->index]) && (new ExpressionTokenDecoder($this->cursor->sql))->isSymbol($this->cursor->tokens[$this->cursor->index], ['.'])) {
            $this->cursor->index++;
            $column = $this->cursor->tokens[$this->cursor->index] ?? null;
            if ($column === null || !(new ExpressionTokenDecoder($this->cursor->sql))->isIdentifier($column)) {
                throw (new ExpressionTokenDecoder($this->cursor->sql))->unsupported();
            }
            $this->cursor->index++;

            return UpsertExpression::column(
                $this->columnSource($identifier),
                (new ExpressionTokenDecoder($this->cursor->sql))->identifier($column),
            );
        }

        return UpsertExpression::column(UpsertColumnSource::Existing, $identifier);
    }

}
