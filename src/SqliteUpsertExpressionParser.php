<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Shadow\Mutation\UpsertColumnSource;
use ZtdQuery\Shadow\Mutation\UpsertExpression;
use ZtdQuery\Shadow\Mutation\UpsertExpressionKind;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

final class SqliteUpsertExpressionParser
{
    private string $sql = '';
    private string $tableName = '';

    /** @var list<SqlToken> */
    private array $tokens = [];

    private int $index = 0;

    public function parse(string $sql, string $tableName): UpsertExpression
    {
        $this->sql = $sql;
        $this->tableName = $tableName;
        $this->tokens = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens();
        $this->index = 0;
        $expression = $this->parseOr();
        if ($this->index !== count($this->tokens)) {
            throw $this->unsupported();
        }

        return $expression;
    }

    public function parseIfSupported(string $sql, string $tableName): ?UpsertExpression
    {
        try {
            return $this->parse($sql, $tableName);
        } catch (UnsupportedSqlException) {
            return null;
        }
    }

    private function parseOr(): UpsertExpression
    {
        $left = $this->parseAnd();
        while (($this->tokens[$this->index] ?? null)?->isKeyword('OR') === true) {
            $this->index++;
            $left = UpsertExpression::binary(UpsertExpressionKind::Or, $left, $this->parseAnd());
        }

        return $left;
    }

    private function parseAnd(): UpsertExpression
    {
        $left = $this->parseComparison();
        while (($this->tokens[$this->index] ?? null)?->isKeyword('AND') === true) {
            $this->index++;
            $left = UpsertExpression::binary(UpsertExpressionKind::And, $left, $this->parseComparison());
        }

        return $left;
    }

    private function parseComparison(): UpsertExpression
    {
        $left = $this->parseAdditive();
        $operator = $this->comparisonOperator();

        return $operator === null
            ? $left
            : UpsertExpression::binary($operator, $left, $this->parseAdditive());
    }

    private function parseAdditive(): UpsertExpression
    {
        $left = $this->parseMultiplicative();
        while (isset($this->tokens[$this->index]) && $this->isSymbol($this->tokens[$this->index], ['+', '-'])) {
            $operator = $this->tokens[$this->index]->text;
            $this->index++;
            $left = UpsertExpression::binary(
                $operator === '+' ? UpsertExpressionKind::Add : UpsertExpressionKind::Subtract,
                $left,
                $this->parseMultiplicative(),
            );
        }

        return $left;
    }

    private function parseMultiplicative(): UpsertExpression
    {
        $left = $this->parseUnary();
        while (isset($this->tokens[$this->index])
            && $this->isSymbol($this->tokens[$this->index], ['*', '/', '%'])
        ) {
            $operator = $this->tokens[$this->index]->text;
            $this->index++;
            $kind = $operator === '*'
                ? UpsertExpressionKind::Multiply
                : ($operator === '/' ? UpsertExpressionKind::Divide : UpsertExpressionKind::Modulo);
            $left = UpsertExpression::binary($kind, $left, $this->parseUnary());
        }

        return $left;
    }

    private function parseUnary(): UpsertExpression
    {
        $token = $this->tokens[$this->index] ?? null;
        if ($token?->isKeyword('NOT') === true) {
            $this->index++;

            return UpsertExpression::unary(UpsertExpressionKind::Not, $this->parseUnary());
        }
        if ($token !== null && $this->isSymbol($token, ['+', '-'])) {
            $this->index++;

            return UpsertExpression::unary(
                $token->text === '+' ? UpsertExpressionKind::UnaryPlus : UpsertExpressionKind::UnaryMinus,
                $this->parseUnary(),
            );
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): UpsertExpression
    {
        $token = $this->tokens[$this->index] ?? null;
        if ($token === null) {
            throw $this->unsupported();
        }
        if ($this->isSymbol($token, ['('])) {
            $this->index++;
            $expression = $this->parseOr();
            if (!isset($this->tokens[$this->index]) || !$this->isSymbol($this->tokens[$this->index], [')'])) {
                throw $this->unsupported();
            }
            $this->index++;

            return $expression;
        }
        if ($token->kind === SqlTokenKind::Number) {
            $this->index++;

            return UpsertExpression::literal($this->number($token->text));
        }
        if ($token->kind === SqlTokenKind::String) {
            $this->index++;

            return UpsertExpression::literal($this->string($token->text));
        }
        if ($token->isKeyword('NULL')) {
            $this->index++;

            return UpsertExpression::literal(null);
        }
        if ($token->isKeyword('TRUE') || $token->isKeyword('FALSE')) {
            $this->index++;

            return UpsertExpression::literal($token->isKeyword('TRUE'));
        }
        if (!$this->isIdentifier($token)) {
            throw $this->unsupported();
        }

        $identifier = $this->identifier($token);
        $this->index++;
        if (isset($this->tokens[$this->index]) && $this->isSymbol($this->tokens[$this->index], ['.'])) {
            $this->index++;
            $column = $this->tokens[$this->index] ?? null;
            if ($column === null || !$this->isIdentifier($column)) {
                throw $this->unsupported();
            }
            $this->index++;

            return UpsertExpression::column(
                $this->columnSource($identifier),
                $this->identifier($column),
            );
        }

        return UpsertExpression::column(UpsertColumnSource::Existing, $identifier);
    }

    private function columnSource(string $qualifier): UpsertColumnSource
    {
        if (strcasecmp($qualifier, 'EXCLUDED') === 0) {
            return UpsertColumnSource::Incoming;
        }
        if (strcasecmp($qualifier, $this->tableName) === 0) {
            return UpsertColumnSource::Existing;
        }

        throw $this->unsupported();
    }

    private function comparisonOperator(): ?UpsertExpressionKind
    {
        $first = $this->tokens[$this->index] ?? null;
        if ($first === null || !$this->isSymbol($first, ['=', '!', '<', '>'])) {
            return null;
        }
        $operator = $first->text;
        $second = $this->tokens[$this->index + 1] ?? null;
        if ($second !== null && $this->isSymbol($second, ['=', '>']) && $operator !== '=') {
            $operator .= $second->text;
            $this->index++;
        }
        $this->index++;

        return match ($operator) {
            '=' => UpsertExpressionKind::Equal,
            '!=', '<>' => UpsertExpressionKind::NotEqual,
            '<' => UpsertExpressionKind::Less,
            '<=' => UpsertExpressionKind::LessOrEqual,
            '>' => UpsertExpressionKind::Greater,
            '>=' => UpsertExpressionKind::GreaterOrEqual,
            default => throw $this->unsupported(),
        };
    }

    /** @param list<string> $symbols */
    private function isSymbol(SqlToken $token, array $symbols): bool
    {
        return $token->kind === SqlTokenKind::Symbol && in_array($token->text, $symbols, true);
    }

    private function isIdentifier(SqlToken $token): bool
    {
        if ($token->kind === SqlTokenKind::Word) {
            return true;
        }

        return $token->kind === SqlTokenKind::QuotedIdentifier;
    }

    private function identifier(SqlToken $token): string
    {
        if ($token->kind === SqlTokenKind::Word) {
            return $token->text;
        }

        $quote = $token->text[0] ?? '';

        return str_replace($quote . $quote, $quote, substr($token->text, 1, -1));
    }

    private function number(string $literal): int|float
    {
        $literal = str_replace('_', '', $literal);

        return strpbrk($literal, '.eE') === false ? (int) $literal : (float) $literal;
    }

    private function string(string $literal): string
    {
        return str_replace("''", "'", substr($literal, 1, -1));
    }

    private function unsupported(): UnsupportedSqlException
    {
        return new UnsupportedSqlException($this->sql, 'Unsupported UPSERT expression');
    }
}
