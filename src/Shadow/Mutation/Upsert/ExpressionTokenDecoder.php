<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;

/**
 * Decodes upsert expression tokens and describes unsupported syntax.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ExpressionTokenDecoder
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private string $sql
    ) {
    }

    /**
     * @param list<string> $symbols
     */
    public function isSymbol(SqlToken $token, array $symbols): bool
    {
        return $token->kind === SqlTokenKind::Symbol && in_array($token->text, $symbols, true);
    }

    /**
     * Decodes upsert expression tokens and describes unsupported syntax.
     */
    public function isIdentifier(SqlToken $token): bool
    {
        if ($token->kind === SqlTokenKind::Word) {
            return true;
        }

        return $token->kind === SqlTokenKind::QuotedIdentifier;
    }

    /**
     * Decodes upsert expression tokens and describes unsupported syntax.
     */
    public function identifier(SqlToken $token): string
    {
        if ($token->kind === SqlTokenKind::Word) {
            return $token->text;
        }

        $quote = $token->text[0] ?? '';

        return str_replace($quote . $quote, $quote, substr($token->text, 1, -1));
    }

    /**
     * Decodes upsert expression tokens and describes unsupported syntax.
     */
    public function number(string $literal): int|float
    {
        $literal = str_replace('_', '', $literal);

        return strpbrk($literal, '.eE') === false ? (int) $literal : (float) $literal;
    }

    /**
     * Decodes upsert expression tokens and describes unsupported syntax.
     */
    public function string(string $literal): string
    {
        return str_replace("''", "'", substr($literal, 1, -1));
    }

    /**
     * Decodes upsert expression tokens and describes unsupported syntax.
     */
    public function unsupported(): UnsupportedSqlException
    {
        return new UnsupportedSqlException($this->sql, 'Unsupported UPSERT expression');
    }
}
