<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

/**
 * Lightweight SQL parser for SQLite.
 *
 * Uses regex-based parsing focused on the SQL subset needed by ZTD:
 * SELECT, INSERT, UPDATE, DELETE, CREATE TABLE, DROP TABLE, ALTER TABLE ADD COLUMN.
 *
 * Returns structured representations of parsed statements.
 */
final class SqliteParser
{
    /**
     * Classify the type of a SQL statement.
     *
     * @return string|null Statement type: 'SELECT', 'INSERT', 'UPDATE', 'DELETE',
     *                     'CREATE_TABLE', 'DROP_TABLE', 'ALTER_TABLE', or null if unsupported.
     */
    public function classifyStatement(string $sql): ?string
    {
        $trimmed = $this->stripComments($sql);
        $trimmed = ltrim($trimmed);

        if ($trimmed === '') {
            return null;
        }

        $upper = strtoupper($trimmed);

        if (str_starts_with($upper, 'WITH')) {
            return $this->classifyWithStatement($trimmed);
        }

        if (str_starts_with($upper, 'SELECT')) {
            return 'SELECT';
        }

        if (str_starts_with($upper, 'INSERT') || str_starts_with($upper, 'REPLACE')) {
            return 'INSERT';
        }

        if (str_starts_with($upper, 'UPDATE')) {
            return 'UPDATE';
        }

        if (str_starts_with($upper, 'DELETE')) {
            return 'DELETE';
        }

        if (preg_match('/^CREATE\s+(TEMPORARY\s+)?TABLE\b/i', $trimmed) === 1) {
            return 'CREATE_TABLE';
        }

        if (preg_match('/^DROP\s+TABLE\b/i', $trimmed) === 1) {
            return 'DROP_TABLE';
        }

        if (preg_match('/^ALTER\s+TABLE\b/i', $trimmed) === 1) {
            return 'ALTER_TABLE';
        }

        return null;
    }

    /**
     * Split a SQL string into individual statements.
     *
     * @return array<int, string>
     */
    public function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $depth = 0;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];

            if ($inSingleQuote) {
                $current .= $char;
                if ($char === '\'' && ($i + 1 < $len) && $sql[$i + 1] === '\'') {
                    $current .= $sql[$i + 1];
                    $i++;
                } elseif ($char === '\'') {
                    $inSingleQuote = false;
                }
                continue;
            }

            if ($inDoubleQuote) {
                $current .= $char;
                if ($char === '"' && ($i + 1 < $len) && $sql[$i + 1] === '"') {
                    $current .= $sql[$i + 1];
                    $i++;
                } elseif ($char === '"') {
                    $inDoubleQuote = false;
                }
                continue;
            }

            if ($char === '\'') {
                $inSingleQuote = true;
                $current .= $char;
                continue;
            }

            if ($char === '"') {
                $inDoubleQuote = true;
                $current .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }

            if ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                $current .= $char;
                continue;
            }

            if ($char === '-' && ($i + 1 < $len) && $sql[$i + 1] === '-') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    $i = $len;
                } else {
                    $current .= substr($sql, $i, $end - $i);
                    $i = $end - 1;
                }
                continue;
            }

            if ($char === '/' && ($i + 1 < $len) && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    $current .= substr($sql, $i);
                    $i = $len;
                } else {
                    $current .= substr($sql, $i, $end + 2 - $i);
                    $i = $end + 1;
                }
                continue;
            }

            if ($char === ';' && $depth === 0) {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Extract the target table name from a DML statement.
     */
    public function extractTargetTable(string $sql): ?string
    {
        $type = $this->classifyStatement($sql);
        if ($type === null) {
            return null;
        }

        return match ($type) {
            'INSERT' => $this->extractInsertTable($sql),
            'UPDATE' => $this->extractUpdateTable($sql),
            'DELETE' => $this->extractDeleteTable($sql),
            'CREATE_TABLE' => $this->extractCreateTableName($sql),
            'DROP_TABLE' => $this->extractDropTableName($sql),
            'ALTER_TABLE' => $this->extractAlterTableName($sql),
            default => null,
        };
    }

    /**
     * Extract table names referenced in a SELECT statement.
     *
     * @return array<int, string>
     */
    public function extractSelectTables(string $sql): array
    {
        $tables = [];

        if (preg_match('/\bFROM\s+(.+?)(?:\s+WHERE\b|\s+GROUP\s+BY\b|\s+HAVING\b|\s+ORDER\s+BY\b|\s+LIMIT\b|\s+UNION\b|$)/is', $sql, $matches) === 1) {
            $fromClause = $matches[1];
            $fromOnly = preg_replace('/\b(?:INNER|LEFT|RIGHT|CROSS|NATURAL)\s+JOIN\b.*/is', '', $fromClause);
            $fromOnly = preg_replace('/\bJOIN\b.*/is', '', $fromOnly ?? $fromClause);

            $parts = explode(',', $fromOnly ?? $fromClause);
            foreach ($parts as $part) {
                $table = $this->extractTableFromExpr(trim($part));
                if ($table !== null) {
                    $tables[] = $table;
                }
            }
        }

        if (preg_match_all('/\bJOIN\s+("(?:[^"]|"")*"|[^\s(]+)/i', $sql, $joinMatches) > 0) {
            foreach ($joinMatches[1] as $joinTable) {
                $table = $this->unquoteIdentifier($joinTable);
                if ($table !== '') {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * Extract columns from an INSERT statement.
     *
     * @return array<int, string>
     */
    public function extractInsertColumns(string $sql): array
    {
        if (preg_match('/\bINTO\s+(?:"(?:[^"]|"")*"|[^\s(]+)\s*\(([^)]+)\)\s*(?:VALUES|SELECT)/i', $sql, $matches) === 1) {
            return $this->parseColumnList($matches[1]);
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
        $upper = strtoupper($sql);
        $valuesPos = strpos($upper, 'VALUES');
        if ($valuesPos === false) {
            return [];
        }

        $rest = substr($sql, $valuesPos + 6);

        return $this->parseValueSets($rest);
    }

    /**
     * Extract SET assignments from an UPDATE statement.
     *
     * @return array<string, string> Column name => value expression.
     */
    public function extractUpdateAssignments(string $sql): array
    {
        if (preg_match('/\bSET\s+(.+?)(?:\s+WHERE\b|\s+ORDER\s+BY\b|\s+LIMIT\b|$)/is', $sql, $matches) !== 1) {
            return [];
        }

        return $this->parseAssignments($matches[1]);
    }

    /**
     * Extract WHERE clause from a DML statement.
     */
    public function extractWhereClause(string $sql): ?string
    {
        if (preg_match('/\bWHERE\s+(.+?)(?:\s+ORDER\s+BY\b|\s+LIMIT\b|\s+GROUP\s+BY\b|\s+HAVING\b|$)/is', $sql, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract ORDER BY clause from a statement.
     */
    public function extractOrderByClause(string $sql): ?string
    {
        if (preg_match('/\bORDER\s+BY\s+(.+?)(?:\s+LIMIT\b|$)/is', $sql, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract LIMIT clause from a statement.
     */
    public function extractLimitClause(string $sql): ?string
    {
        if (preg_match('/\bLIMIT\s+(.+?)$/is', $sql, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Check if an INSERT statement has ON CONFLICT clause (upsert).
     */
    public function hasOnConflict(string $sql): bool
    {
        return preg_match('/\bON\s+CONFLICT\b/i', $sql) === 1;
    }

    /**
     * Check if the statement is INSERT OR REPLACE / REPLACE INTO.
     */
    public function isReplace(string $sql): bool
    {
        $trimmed = ltrim($this->stripComments($sql));
        $upper = strtoupper($trimmed);

        return str_starts_with($upper, 'REPLACE')
            || (bool) preg_match('/^INSERT\s+OR\s+REPLACE\b/i', $trimmed);
    }

    /**
     * Check if the statement is INSERT OR IGNORE / INSERT IGNORE.
     */
    public function isInsertIgnore(string $sql): bool
    {
        $trimmed = ltrim($this->stripComments($sql));

        return (bool) preg_match('/^INSERT\s+OR\s+IGNORE\b/i', $trimmed);
    }

    /**
     * Extract ON CONFLICT update columns from an upsert statement.
     *
     * @return array<string, string> Column name => value expression.
     */
    public function extractOnConflictUpdates(string $sql): array
    {
        if (preg_match('/\bON\s+CONFLICT\s*(?:\([^)]*\))?\s*DO\s+UPDATE\s+SET\s+(.+?)$/is', $sql, $matches) !== 1) {
            return [];
        }

        return $this->parseAssignments($matches[1]);
    }

    /**
     * Check if an INSERT has a SELECT subquery.
     */
    public function hasInsertSelect(string $sql): bool
    {
        $upper = strtoupper($sql);
        return preg_match('/\bINTO\s+(?:"(?:[^"]|"")*"|[^\s(]+)\s*(?:\([^)]*\)\s*)?SELECT\b/i', $sql) === 1;
    }

    /**
     * Extract the SELECT subquery from an INSERT ... SELECT statement.
     */
    public function extractInsertSelect(string $sql): ?string
    {
        if (preg_match('/\bINTO\s+(?:"(?:[^"]|"")*"|[^\s(]+)\s*(?:\([^)]*\)\s*)?(SELECT\b.+)$/is', $sql, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Strip SQL comments from a string.
     */
    public function stripComments(string $sql): string
    {
        $result = (string) preg_replace('/\/\*.*?\*\//s', '', $sql);
        $result = (string) preg_replace('/--[^\n]*/', '', $result);
        $result = (string) preg_replace('/#[^\n]*/', '', $result);

        return trim($result);
    }

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

    private function classifyWithStatement(string $sql): ?string
    {
        $upper = strtoupper($sql);
        $len = strlen($upper);
        $depth = 0;
        $seenCteBody = false;
        $quote = '';

        for ($i = 0; $i < $len; $i++) {
            $char = $upper[$i];

            if ($quote !== '') {
                if ($char === $quote) {
                    if ($i + 1 < $len && $upper[$i + 1] === $quote) {
                        $i++;
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $seenCteBody = true;
                continue;
            }

            if ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                continue;
            }

            if (!$seenCteBody || $depth !== 0 || !ctype_alpha($char)) {
                continue;
            }

            $prev = $i > 0 ? $upper[$i - 1] : '';
            if (ctype_alpha($prev)) {
                continue;
            }

            $j = $i;
            while ($j < $len && ctype_alpha($upper[$j])) {
                $j++;
            }

            $keyword = substr($upper, $i, $j - $i);

            $result = match ($keyword) {
                'SELECT' => 'SELECT',
                'INSERT', 'REPLACE' => 'INSERT',
                'UPDATE' => 'UPDATE',
                'DELETE' => 'DELETE',
                default => null,
            };

            if ($result !== null) {
                return $result;
            }

            $i = $j - 1;
        }

        return null;
    }

    private function extractInsertTable(string $sql): ?string
    {
        if (preg_match('/\bINTO\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)/i', $sql, $matches) === 1) {
            return $this->unquoteIdentifier($matches[1]);
        }

        if (preg_match('/^REPLACE\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)/i', trim($sql), $matches) === 1) {
            return $this->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    private function extractUpdateTable(string $sql): ?string
    {
        if (preg_match('/^UPDATE\s+(?:OR\s+(?:ROLLBACK|ABORT|REPLACE|FAIL|IGNORE)\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s,]+)/i', trim($sql), $matches) === 1) {
            return $this->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    private function extractDeleteTable(string $sql): ?string
    {
        if (preg_match('/\bFROM\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s,]+)/i', $sql, $matches) === 1) {
            return $this->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    private function extractCreateTableName(string $sql): ?string
    {
        if (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)/i', trim($sql), $matches) === 1) {
            return $this->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    private function extractDropTableName(string $sql): ?string
    {
        if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s;]+)/i', trim($sql), $matches) === 1) {
            return $this->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    private function extractAlterTableName(string $sql): ?string
    {
        if (preg_match('/^ALTER\s+TABLE\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s]+)/i', trim($sql), $matches) === 1) {
            return $this->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    private function extractTableFromExpr(string $expr): ?string
    {
        if ($expr === '') {
            return null;
        }

        $parts = preg_split('/\s+AS\s+/i', $expr, 2);
        if ($parts === false) {
            $parts = [$expr];
        }
        $tablePart = trim($parts[0]);

        if (count($parts) === 1) {
            $spaceParts = preg_split('/\s+/', $tablePart);
            if ($spaceParts !== false && $spaceParts !== []) {
                $tablePart = $spaceParts[0];
            }
        }

        return $this->unquoteIdentifier($tablePart);
    }

    /**
     * Parse a comma-separated column list.
     *
     * @return array<int, string>
     */
    private function parseColumnList(string $columnList): array
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

    /**
     * Parse VALUE sets: (val1, val2), (val3, val4).
     *
     * @return array<int, array<int, string>>
     */
    private function parseValueSets(string $rest): array
    {
        $sets = [];
        $len = strlen($rest);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && ($rest[$i] === ' ' || $rest[$i] === ',' || $rest[$i] === "\n" || $rest[$i] === "\r" || $rest[$i] === "\t")) {
                $i++;
            }

            if ($i >= $len || $rest[$i] !== '(') {
                break;
            }

            $i++;
            $values = [];
            $current = '';
            $depth = 0;
            $inQuote = '';

            while ($i < $len) {
                $char = $rest[$i];

                if ($inQuote !== '') {
                    $current .= $char;
                    if ($char === $inQuote) {
                        if ($i + 1 < $len && $rest[$i + 1] === $inQuote) {
                            $current .= $rest[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $inQuote = '';
                    }
                    $i++;
                    continue;
                }

                if ($char === '\'' || $char === '"') {
                    $inQuote = $char;
                    $current .= $char;
                    $i++;
                    continue;
                }

                if ($char === '(') {
                    $depth++;
                    $current .= $char;
                    $i++;
                    continue;
                }

                if ($char === ')') {
                    if ($depth > 0) {
                        $depth--;
                        $current .= $char;
                        $i++;
                        continue;
                    }
                    $val = trim($current);
                    if ($val !== '') {
                        $values[] = $val;
                    }
                    $i++;
                    break;
                }

                if ($char === ',' && $depth === 0) {
                    $val = trim($current);
                    $values[] = $val;
                    $current = '';
                    $i++;
                    continue;
                }

                $current .= $char;
                $i++;
            }

            if ($values !== []) {
                $sets[] = $values;
            }
        }

        return $sets;
    }

    /**
     * Parse SET assignments: col1 = val1, col2 = val2.
     *
     * @return array<string, string>
     */
    private function parseAssignments(string $setClause): array
    {
        $assignments = [];
        $len = strlen($setClause);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && ctype_space($setClause[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            $colStart = $i;
            if ($setClause[$i] === '"' || $setClause[$i] === '`' || $setClause[$i] === '[') {
                $quoteChar = $setClause[$i] === '[' ? ']' : $setClause[$i];
                $i++;
                while ($i < $len && $setClause[$i] !== $quoteChar) {
                    if ($setClause[$i] === $quoteChar && $i + 1 < $len && $setClause[$i + 1] === $quoteChar) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                }
                if ($i < $len) {
                    $i++;
                }
            } else {
                while ($i < $len && $setClause[$i] !== '=' && !ctype_space($setClause[$i])) {
                    $i++;
                }
            }
            $colName = $this->unquoteIdentifier(trim(substr($setClause, $colStart, $i - $colStart)));
            if (str_contains($colName, '.')) {
                $parts = explode('.', $colName);
                $colName = $this->unquoteIdentifier(trim(end($parts)));
            }

            while ($i < $len && (ctype_space($setClause[$i]) || $setClause[$i] === '=')) {
                $i++;
            }

            $valStart = $i;
            $depth = 0;
            $inQuote = '';

            while ($i < $len) {
                $char = $setClause[$i];

                if ($inQuote !== '') {
                    if ($char === $inQuote) {
                        if ($i + 1 < $len && $setClause[$i + 1] === $inQuote) {
                            $i += 2;
                            continue;
                        }
                        $inQuote = '';
                    }
                    $i++;
                    continue;
                }

                if ($char === '\'' || $char === '"') {
                    $inQuote = $char;
                    $i++;
                    continue;
                }

                if ($char === '(') {
                    $depth++;
                    $i++;
                    continue;
                }

                if ($char === ')') {
                    if ($depth > 0) {
                        $depth--;
                        $i++;
                        continue;
                    }
                    break;
                }

                if ($char === ',' && $depth === 0) {
                    break;
                }

                $i++;
            }

            $value = trim(substr($setClause, $valStart, $i - $valStart));
            if ($colName !== '' && $value !== '') {
                $assignments[$colName] = $value;
            }

            if ($i < $len && $setClause[$i] === ',') {
                $i++;
            }
        }

        return $assignments;
    }
}
