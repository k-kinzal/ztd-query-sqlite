<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Schema\TableDefinition;

/**
 * SQLite implementation of SchemaParser.
 *
 * Parses CREATE TABLE statements using regex-based parsing
 * appropriate for SQLite's simpler DDL syntax.
 */
final class SqliteSchemaParser implements SchemaParser
{
    /**
     * {@inheritDoc}
     */
    public function parse(string $createTableSql): ?TableDefinition
    {
        $trimmed = trim($createTableSql);

        if (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)\s*\((.+)\)\s*(?:WITHOUT\s+ROWID\s*)?;?\s*$/is', $trimmed, $matches) !== 1) {
            return null;
        }

        $body = $matches[1];

        $columns = [];
        $columnTypes = [];
        $primaryKeys = [];
        $notNullColumns = [];
        $uniqueConstraints = [];
        $uniqueIndex = 0;

        $definitions = $this->splitColumnDefinitions($body);

        foreach ($definitions as $def) {
            $def = trim($def);
            if ($def === '') {
                continue;
            }

            $upperDef = strtoupper(ltrim($def));

            if (str_starts_with($upperDef, 'PRIMARY KEY')) {
                if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $def, $pkMatches) === 1) {
                    $pkCols = $this->parseColumnNameList($pkMatches[1]);
                    $primaryKeys = array_merge($primaryKeys, $pkCols);
                }
                continue;
            }

            if (str_starts_with($upperDef, 'UNIQUE')) {
                if (preg_match('/^UNIQUE\s*\(([^)]+)\)/i', $def, $uniqueMatches) === 1) {
                    $uniqueCols = $this->parseColumnNameList($uniqueMatches[1]);
                    if ($uniqueCols !== []) {
                        $keyName = 'unique_' . $uniqueIndex++;
                        $uniqueConstraints[$keyName] = $uniqueCols;
                    }
                }
                continue;
            }

            if (str_starts_with($upperDef, 'CONSTRAINT')) {
                if (preg_match('/^CONSTRAINT\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|[^\s]+)\s+PRIMARY\s+KEY\s*\(([^)]+)\)/i', $def, $pkMatches) === 1) {
                    $pkCols = $this->parseColumnNameList($pkMatches[1]);
                    $primaryKeys = array_merge($primaryKeys, $pkCols);
                }
                if (preg_match('/^CONSTRAINT\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|[^\s]+)\s+UNIQUE\s*\(([^)]+)\)/i', $def, $uniqueMatches) === 1) {
                    $uniqueCols = $this->parseColumnNameList($uniqueMatches[1]);
                    if ($uniqueCols !== []) {
                        $keyName = 'unique_' . $uniqueIndex++;
                        $uniqueConstraints[$keyName] = $uniqueCols;
                    }
                }
                continue;
            }

            if (str_starts_with($upperDef, 'FOREIGN KEY') || str_starts_with($upperDef, 'CHECK')) {
                continue;
            }

            $colInfo = $this->parseColumnDefinition($def);
            if ($colInfo === null) {
                continue;
            }

            $columns[] = $colInfo['name'];

            if ($colInfo['type'] !== null) {
                $columnTypes[$colInfo['name']] = $colInfo['type'];
            }

            if ($colInfo['notNull']) {
                $notNullColumns[] = $colInfo['name'];
            }

            if ($colInfo['primaryKey']) {
                $primaryKeys[] = $colInfo['name'];
                if (!in_array($colInfo['name'], $notNullColumns, true)) {
                    $notNullColumns[] = $colInfo['name'];
                }
            }

            if ($colInfo['unique']) {
                $keyName = $colInfo['name'] . '_UNIQUE';
                $uniqueConstraints[$keyName] = [$colInfo['name']];
            }
        }

        if ($columns === []) {
            return null;
        }

        foreach ($uniqueConstraints as $constraintColumns) {
            foreach ($constraintColumns as $col) {
                if (!in_array($col, $columns, true)) {
                    return null;
                }
            }
        }

        /** @var array<string, ColumnType> $typedColumns */
        $typedColumns = [];
        foreach ($columnTypes as $colName => $nativeType) {
            $typedColumns[$colName] = new ColumnType(
                $this->mapToColumnTypeFamily($nativeType),
                $nativeType,
            );
        }

        return new TableDefinition(
            $columns,
            $columnTypes,
            array_values(array_unique($primaryKeys)),
            array_values(array_unique($notNullColumns)),
            $uniqueConstraints,
            $typedColumns,
        );
    }

    /**
     * Split column/constraint definitions by commas, respecting parentheses.
     *
     * @return array<int, string>
     */
    private function splitColumnDefinitions(string $body): array
    {
        $definitions = [];
        $current = '';
        $depth = 0;
        $len = strlen($body);
        $inQuote = '';

        for ($i = 0; $i < $len; $i++) {
            $char = $body[$i];

            if ($inQuote !== '') {
                $current .= $char;
                if ($char === $inQuote) {
                    if ($i + 1 < $len && $body[$i + 1] === $inQuote) {
                        $current .= $body[$i + 1];
                        $i++;
                    } else {
                        $inQuote = '';
                    }
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $inQuote = $char;
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

            if ($char === ',' && $depth === 0) {
                $definitions[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $last = trim($current);
        if ($last !== '') {
            $definitions[] = $last;
        }

        return $definitions;
    }

    /**
     * Parse a single column definition.
     *
     * @return array{name: string, type: string|null, notNull: bool, primaryKey: bool, unique: bool}|null
     */
    private function parseColumnDefinition(string $def): ?array
    {
        $pattern = '/^("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(,]+)/';
        if (preg_match($pattern, $def, $matches) !== 1) {
            return null;
        }

        $parser = new SqliteParser();
        $name = $parser->unquoteIdentifier($matches[1]);
        if ($name === '') {
            return null;
        }

        $rest = trim(substr($def, strlen($matches[1])));
        $upperRest = strtoupper($rest);

        $type = null;
        if ($rest !== '' && !str_starts_with($upperRest, 'PRIMARY')
            && !str_starts_with($upperRest, 'NOT')
            && !str_starts_with($upperRest, 'UNIQUE')
            && !str_starts_with($upperRest, 'CHECK')
            && !str_starts_with($upperRest, 'DEFAULT')
            && !str_starts_with($upperRest, 'REFERENCES')
            && !str_starts_with($upperRest, 'CONSTRAINT')
            && !str_starts_with($upperRest, 'COLLATE')
            && !str_starts_with($upperRest, 'GENERATED')
            && !str_starts_with($upperRest, 'AS')
        ) {
            $type = $this->extractColumnType($rest);
        }

        $upperDef = strtoupper($def);
        $notNull = str_contains($upperDef, 'NOT NULL');
        $primaryKey = (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $def);
        $unique = (bool) preg_match('/\bUNIQUE\b/i', $def) && !$primaryKey;

        return [
            'name' => $name,
            'type' => $type,
            'notNull' => $notNull,
            'primaryKey' => $primaryKey,
            'unique' => $unique,
        ];
    }

    /**
     * Extract the column type from the rest of a column definition.
     */
    private function extractColumnType(string $rest): ?string
    {
        if (preg_match('/^([A-Za-z_]\w*(?:\s+\w+)*?)(?:\s*\(([^)]*)\))?\s*(?:PRIMARY|NOT|UNIQUE|CHECK|DEFAULT|REFERENCES|CONSTRAINT|COLLATE|GENERATED|AS|$)/i', $rest, $matches) === 1) {
            $typeName = strtoupper(trim($matches[1]));
            if ($typeName === '') {
                return null;
            }

            $firstWord = explode(' ', $typeName)[0];
            $nonTypeKeywords = ['PRIMARY', 'NOT', 'UNIQUE', 'CHECK', 'DEFAULT', 'REFERENCES', 'CONSTRAINT', 'COLLATE', 'GENERATED', 'AS', 'ON', 'FOREIGN'];
            if (in_array($firstWord, $nonTypeKeywords, true)) {
                return null;
            }

            if (isset($matches[2]) && $matches[2] !== '') {
                return $typeName . '(' . $matches[2] . ')';
            }

            return $typeName;
        }

        return null;
    }

    /**
     * Map a SQLite native type string to ColumnTypeFamily.
     */
    private function mapToColumnTypeFamily(string $nativeType): ColumnTypeFamily
    {
        $upper = strtoupper(preg_replace('/\(.*\)/', '', $nativeType) ?? $nativeType);
        $upper = trim($upper);

        return match ($upper) {
            'INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'INT2', 'INT8' => ColumnTypeFamily::INTEGER,
            'REAL', 'DOUBLE', 'DOUBLE PRECISION', 'FLOAT' => ColumnTypeFamily::FLOAT,
            'DECIMAL', 'NUMERIC' => ColumnTypeFamily::DECIMAL,
            'BOOLEAN', 'BOOL' => ColumnTypeFamily::BOOLEAN,
            'TEXT', 'CLOB' => ColumnTypeFamily::TEXT,
            'CHAR', 'CHARACTER', 'VARCHAR', 'VARYING CHARACTER', 'NCHAR', 'NATIVE CHARACTER', 'NVARCHAR' => ColumnTypeFamily::STRING,
            'BLOB' => ColumnTypeFamily::BINARY,
            'DATE' => ColumnTypeFamily::DATE,
            'TIME' => ColumnTypeFamily::TIME,
            'DATETIME' => ColumnTypeFamily::DATETIME,
            'TIMESTAMP' => ColumnTypeFamily::TIMESTAMP,
            'JSON' => ColumnTypeFamily::JSON,
            default => ColumnTypeFamily::UNKNOWN,
        };
    }

    /**
     * Parse a comma-separated column name list.
     *
     * @return array<int, string>
     */
    private function parseColumnNameList(string $list): array
    {
        $columns = [];
        $parser = new SqliteParser();
        $parts = explode(',', $list);
        foreach ($parts as $part) {
            $col = trim($part);
            if ($col !== '') {
                $columns[] = $parser->unquoteIdentifier($col);
            }
        }

        return $columns;
    }
}
