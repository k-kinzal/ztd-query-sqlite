<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Dml\Insert;

/**
 * Extracts INSERT sources and conflict-handling clauses.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class InsertClauseParser
{
    /**
     * Extract columns from an INSERT statement.
     *
     * @return array<int, string>
     */
    public function extractInsertColumns(string $sql): array
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        if (preg_match('/\bINTO\s+(?:"(?:[^"]|"")*"|[^\s(]+)\s*\(([^)]+)\)\s*(?:VALUES|SELECT)/i', $sql, $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->parseColumnList($matches[1]);
        }

        return [];
    }

    /**
     * Extract VALUES from an INSERT statement.
     *
     * @return array<int, array<int, string>>
     */
    public function extractInsertValues(string $sql): array
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        $source = $this->findInsertSourceClause($sql);
        if ($source === null) {
            return [];
        }
        if ($source['keyword'] !== 'VALUES') {
            return [];
        }

        $rest = substr($sql, $source['offset'] + strlen($source['keyword']));

        return (new \ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\ValueListParser())->parseValueSets($rest);
    }

    /**
     * Check if an INSERT statement has ON CONFLICT clause (upsert).
     */
    public function hasOnConflict(string $sql): bool
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        return preg_match('/\bON\s+CONFLICT\b/i', $sql) === 1;
    }

    /**
     * Check if the statement is INSERT OR REPLACE / REPLACE INTO.
     */
    public function isReplace(string $sql): bool
    {
        $trimmed = ltrim((new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql));
        $upper = strtoupper($trimmed);

        return str_starts_with($upper, 'REPLACE')
            || (bool) preg_match('/^INSERT\s+OR\s+REPLACE\b/i', $trimmed);
    }

    /**
     * Check if the statement is INSERT OR IGNORE / INSERT IGNORE.
     */
    public function isInsertIgnore(string $sql): bool
    {
        $trimmed = ltrim((new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql));

        return (bool) preg_match('/^INSERT\s+OR\s+IGNORE\b/i', $trimmed);
    }

    /**
     * Check if an INSERT has a SELECT subquery.
     */
    public function hasInsertSelect(string $sql): bool
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        $source = $this->findInsertSourceClause($sql);

        return $source !== null && $source['keyword'] === 'SELECT';
    }

    /**
     * Extract the SELECT subquery from an INSERT ... SELECT statement.
     */
    public function extractInsertSelect(string $sql): ?string
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        $source = $this->findInsertSourceClause($sql);
        if ($source !== null && $source['keyword'] === 'SELECT') {
            return substr($sql, $source['offset']);
        }

        return null;
    }

    /**
     * @return array{keyword: string, offset: int}|null
     */
    public function findInsertSourceClause(string $sql): ?array
    {
        $foundInsert = false;
        foreach ((new \ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner())->scanTopLevelKeywords($sql) as $token) {
            if (!$foundInsert) {
                $foundInsert = $token['keyword'] === 'INSERT' || $token['keyword'] === 'REPLACE';
                continue;
            }

            if ($token['keyword'] === 'VALUES' || $token['keyword'] === 'SELECT') {
                return [
                    'keyword' => $token['keyword'],
                    'offset' => $token['offset'],
                ];
            }
        }

        return null;
    }
}
