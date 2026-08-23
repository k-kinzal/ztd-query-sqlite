<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Rewrite\ReturningProjection;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

final class SqliteReturningProjectionParser
{
    public function parse(string $sql): ?ReturningProjection
    {
        $clause = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->topLevelClause(['RETURNING']);
        if ($clause === null) {
            return null;
        }

        $items = [];
        foreach (SqlTokenStream::tokenize(rtrim($clause, "; \t\n\r\0\x0B"), SqliteLexerProfile::create())->splitTopLevel() as $expression) {
            $item = $this->parseItem($expression);
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

    /** @return array{source: string|null, output: string|null}|null */
    private function parseItem(string $expression): ?array
    {
        $tokens = SqlTokenStream::tokenize($expression, SqliteLexerProfile::create())->significantTokens();
        $alias = null;
        $asIndex = $this->asIndex($tokens);
        if ($asIndex !== null) {
            if ($asIndex + 2 !== count($tokens)) {
                return null;
            }
            $aliasToken = $tokens[$asIndex + 1] ?? null;
            if ($aliasToken === null) {
                return null;
            }
            $alias = $this->identifierName($aliasToken);
            if ($alias === null) {
                return null;
            }
            $tokens = array_slice($tokens, 0, $asIndex);
        }

        if (!$this->isIdentifierPath($tokens)) {
            return null;
        }
        $last = $tokens[count($tokens) - 1];
        if ($last->text === '*') {
            return $alias === null ? ['source' => null, 'output' => null] : null;
        }

        $source = $this->identifierName($last);
        if ($source === null) {
            return null;
        }

        return ['source' => $source, 'output' => $alias];
    }

    /** @param list<SqlToken> $tokens */
    private function asIndex(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($token->isKeyword('AS')) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<SqlToken> $tokens */
    private function isIdentifierPath(array $tokens): bool
    {
        $lastIndex = count($tokens) - 1;
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                if ($this->identifierName($token) !== null) {
                    continue;
                }

                return $index === $lastIndex
                    && $token->kind === SqlTokenKind::Symbol
                    && $token->text === '*';
            }
            if ($token->kind !== SqlTokenKind::Symbol || $token->text !== '.') {
                return false;
            }
        }

        return true;
    }

    private function identifierName(SqlToken $token): ?string
    {
        if ($token->kind === SqlTokenKind::Word) {
            return $token->text;
        }
        if ($token->kind !== SqlTokenKind::QuotedIdentifier || strlen($token->text) <= 2) {
            return null;
        }

        $identifier = $token->text;
        $first = $identifier[0];
        if (!in_array($first, ['"', '`'], true) || $identifier[strlen($identifier) - 1] !== $first) {
            return null;
        }

        return str_replace($first . $first, $first, substr($identifier, 1, -1));
    }
}
