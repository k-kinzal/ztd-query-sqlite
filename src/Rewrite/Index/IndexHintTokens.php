<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Index;

use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;

/**
 * Locates index hints following a table reference and optional alias.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class IndexHintTokens
{
    /**
     * @param list<SqlToken> $tokens
     * @return array{start: int, end: int}|null
     */
    public static function hintRange(array $tokens, int $index): ?array
    {
        $keyword = $tokens[$index] ?? null;
        if ($keyword === null) {
            return null;
        }

        if ($keyword->isKeyword('NOT')) {
            $indexed = $tokens[$index + 1] ?? null;
            if ($indexed === null) {
                return null;
            }
            if (!$indexed->isKeyword('INDEXED')) {
                return null;
            }

            return ['start' => $keyword->offset, 'end' => $indexed->endOffset()];
        }

        if (!$keyword->isKeyword('INDEXED')) {
            return null;
        }
        $by = $tokens[$index + 1] ?? null;
        if ($by === null) {
            return null;
        }
        if (!$by->isKeyword('BY')) {
            return null;
        }
        $nameEnd = self::identifierEndIndex($tokens, $index + 2);
        if ($nameEnd === null) {
            return null;
        }

        return [
            'start' => $keyword->offset,
            'end' => $tokens[$nameEnd - 1]->endOffset(),
        ];
    }

    /**
     * @param list<SqlToken> $tokens
     */
    public static function tokenIndexAtOrAfter(array $tokens, int $offset): int
    {
        foreach ($tokens as $index => $token) {
            if ($token->offset >= $offset) {
                return $index;
            }
        }

        return count($tokens);
    }

    /**
     * @param list<SqlToken> $tokens
     */
    public static function skipAlias(array $tokens, int $index): int
    {
        $candidate = $tokens[$index] ?? null;
        if ($candidate === null) {
            return $index;
        }
        if ($candidate->isKeyword('AS')) {
            return self::identifierEndIndex($tokens, $index + 1) ?? $index;
        }

        if (self::isSourceBoundary($candidate)) {
            return $index;
        }

        return self::identifierEndIndex($tokens, $index) ?? $index;
    }

    /**
     * Locates index hints following a table reference and optional alias.
     */
    public static function isSourceBoundary(SqlToken $token): bool
    {
        foreach ([
            'INDEXED', 'NOT', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET',
            'JOIN', 'LEFT', 'RIGHT', 'FULL', 'INNER', 'CROSS', 'NATURAL', 'ON', 'USING',
            'UNION', 'INTERSECT', 'EXCEPT', 'RETURNING', 'WINDOW', 'FOR',
        ] as $keyword) {
            if ($token->isKeyword($keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<SqlToken> $tokens
     */
    public static function identifierEndIndex(array $tokens, int $index): ?int
    {
        $token = $tokens[$index] ?? null;
        if ($token === null) {
            return null;
        }
        if ($token->kind === SqlTokenKind::Word || $token->kind === SqlTokenKind::QuotedIdentifier) {
            return $index + 1;
        }
        if ($token->kind !== SqlTokenKind::Symbol) {
            return null;
        }
        if ($token->text !== '[') {
            return null;
        }

        $endIndex = $index;
        while (true) {
            $endIndex++;
            $endToken = $tokens[$endIndex] ?? null;
            if ($endToken === null) {
                return null;
            }
            if ($endToken->kind !== SqlTokenKind::Symbol) {
                continue;
            }
            if ($endToken->text !== ']') {
                continue;
            }

            return $endIndex + 1;
        }
    }
}
