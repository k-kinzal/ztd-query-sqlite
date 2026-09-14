<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Lexing;

/**
 * Decodes quoted identifiers and locates identifier token boundaries.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class IdentifierDecoder
{
    /**
     * Unquote a SQL identifier (double-quoted or backtick-quoted).
     */
    public function unquoteIdentifier(string $identifier): string
    {
        $trimmed = trim($identifier);

        if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && $trimmed[strlen($trimmed) - 1] === '"') {
            $inner = substr($trimmed, 1, -1);

            return str_replace('""', '"', $inner);
        }

        if (strlen($trimmed) >= 2 && $trimmed[0] === '`' && $trimmed[strlen($trimmed) - 1] === '`') {
            $inner = substr($trimmed, 1, -1);

            return str_replace('``', '`', $inner);
        }

        if (strlen($trimmed) >= 2 && $trimmed[0] === '[' && $trimmed[strlen($trimmed) - 1] === ']') {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }

    /**
     * @param list<\ZtdQuery\Sql\SqlToken> $tokens
     */
    public function identifierEndIndex(array $tokens, int $index): int
    {
        $token = $tokens[$index] ?? null;
        if ($token?->text !== '[') {
            return $index + 1;
        }

        for (; isset($tokens[$index]); $index++) {
            $endToken = $tokens[$index];
            if ($endToken->text !== ']') {
                continue;
            }
            if (!$endToken->isTopLevel()) {
                continue;
            }
            $following = $tokens[$index + 1] ?? null;
            if ($following?->text === ']' && $following->isTopLevel()) {
                $index++;
                continue;
            }

            return $index + 1;
        }

        return $index;
    }

    /**
     * Parse a comma-separated column list.
     *
     * @return array<int, string>
     */
    public function parseColumnList(string $columnList): array
    {
        $columns = [];
        $parts = explode(',', $columnList);
        foreach ($parts as $part) {
            $col = trim($part);
            if ($col !== '') {
                $columns[] = $this->unquoteIdentifier($col);
            }
        }

        return $columns;
    }
}
