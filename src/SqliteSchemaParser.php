<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Schema\IdentityGenerationStrategy;
use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * SQLite implementation of SchemaParser.
 *
 * Parses CREATE TABLE statements while preserving nested SQL expressions.
 */
final class SqliteSchemaParser implements SchemaParser
{
    /**
     * {@inheritDoc}
     */
    public function parse(string $createTableSql): ?TableDefinition
    {
        $trimmed = trim($createTableSql);

        $body = $this->tableBody($trimmed);
        if ($body === null) {
            return $this->parseFts5VirtualTable($trimmed);
        }

        $columns = [];
        $columnTypes = [];
        /** @var array<string, string> $primaryKeyMap */
        $primaryKeyMap = [];
        $notNullColumns = [];
        $uniqueConstraints = [];
        $columnDefaults = [];
        $generatedExpressions = [];
        $uniqueIndex = 0;
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable($createTableSql);

        $definitions = $this->splitColumnDefinitions($body);

        foreach ($definitions as $def) {
            $def = trim($def);
            if ($def === '') {
                continue;
            }

            $leadingKeyword = $this->leadingKeyword($def);

            if ($leadingKeyword === 'PRIMARY') {
                if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $def, $pkMatches) === 1) {
                    $pkCols = $this->parseColumnNameList($pkMatches[1]);
                    foreach ($pkCols as $primaryKey) {
                        $primaryKeyMap[$primaryKey] = $primaryKey;
                    }
                }
                continue;
            }

            if ($leadingKeyword === 'UNIQUE') {
                if (preg_match('/^UNIQUE\s*\(([^)]+)\)/i', $def, $uniqueMatches) === 1) {
                    $uniqueCols = $this->parseColumnNameList($uniqueMatches[1]);
                    if ($uniqueCols !== []) {
                        $keyName = 'unique_' . $uniqueIndex++;
                        $uniqueConstraints[$keyName] = $uniqueCols;
                    }
                }
                continue;
            }

            if ($leadingKeyword === 'CONSTRAINT') {
                if (preg_match('/^CONSTRAINT\s+(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|[^\s]+)\s+PRIMARY\s+KEY\s*\(([^)]+)\)/i', $def, $pkMatches) === 1) {
                    $pkCols = $this->parseColumnNameList($pkMatches[1]);
                    foreach ($pkCols as $primaryKey) {
                        $primaryKeyMap[$primaryKey] = $primaryKey;
                    }
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

            if ($leadingKeyword === 'FOREIGN' || $leadingKeyword === 'CHECK') {
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
                $primaryKeyMap[$colInfo['name']] = $colInfo['name'];
                if (!in_array($colInfo['name'], $notNullColumns, true)) {
                    $notNullColumns[] = $colInfo['name'];
                }
            }

            if ($colInfo['unique']) {
                $keyName = $colInfo['name'] . '_UNIQUE';
                $uniqueConstraints[$keyName] = [$colInfo['name']];
            }

            if ($colInfo['default'] !== null) {
                $columnDefaults[$colInfo['name']] = $colInfo['default'];
            }
            if ($colInfo['generatedExpression'] !== null) {
                $generatedExpressions[$colInfo['name']] = $colInfo['generatedExpression'];
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
            $typedColumns[$colName] = (new SqliteColumnTypeMapper())->map($nativeType);
        }

        $primaryKeys = array_values($primaryKeyMap);
        $identityStrategies = [];
        if (!self::hasWithoutRowid($trimmed) && count($primaryKeys) === 1) {
            $identityColumn = $primaryKeys[0];
            if (($columnTypes[$identityColumn] ?? null) === 'INTEGER') {
                $identityStrategies[$identityColumn] = IdentityGenerationStrategy::MaxValue;
            }
        }

        return new TableDefinition(
            $columns,
            $columnTypes,
            $primaryKeys,
            array_values(array_unique($notNullColumns)),
            $uniqueConstraints,
            $typedColumns,
            $columnDefaults,
            $identityStrategies,
            $generatedExpressions,
            $foreignKeys,
        );
    }

    private function parseFts5VirtualTable(string $sql): ?TableDefinition
    {
        $stream = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create());
        $tokens = $stream->significantTokens();
        if (($tokens[0] ?? null)?->isKeyword('CREATE') !== true) {
            return null;
        }
        if (($tokens[1] ?? null)?->isKeyword('VIRTUAL') !== true) {
            return null;
        }
        if (($tokens[2] ?? null)?->isKeyword('TABLE') !== true) {
            return null;
        }

        $using = null;
        foreach ($tokens as $token) {
            if (!$token->isTopLevel() || !$token->isKeyword('USING')) {
                continue;
            }
            if ($using !== null) {
                return null;
            }
            $using = $token;
        }
        if ($using === null) {
            return null;
        }

        $module = $stream->significantTokenAfter($using);
        if ($module === null || !$module->isKeyword('FTS5')) {
            return null;
        }
        $opening = $stream->significantTokenAfter($module);
        if ($opening === null) {
            return null;
        }
        $closing = $stream->matchingClosingNestingToken($opening);
        if ($closing === null) {
            return null;
        }
        $suffix = trim(substr($sql, $closing->endOffset()));
        if ($suffix !== '' && $suffix !== ';') {
            return null;
        }

        $body = substr($sql, $opening->endOffset(), $closing->offset - $opening->endOffset());
        $columns = [];
        $parser = new SqliteParser();
        foreach (SqlTokenStream::tokenize($body, SqliteLexerProfile::create())->splitTopLevel() as $definition) {
            $definitionTokens = SqlTokenStream::tokenize($definition, SqliteLexerProfile::create())->significantTokens();
            $name = $definitionTokens[0] ?? null;
            $assignment = $definitionTokens[1] ?? null;
            if ($assignment !== null
                && $assignment->kind === SqlTokenKind::Symbol
                && $assignment->text === '='
            ) {
                continue;
            }
            if ($name === null
                || !in_array($name->kind, [SqlTokenKind::Word, SqlTokenKind::QuotedIdentifier], true)
            ) {
                return null;
            }
            if (count($definitionTokens) > 2) {
                return null;
            }
            $modifier = $definitionTokens[1] ?? null;
            if ($modifier !== null && !$modifier->isKeyword('UNINDEXED')) {
                return null;
            }

            $columns[] = $parser->unquoteIdentifier($name->text);
        }
        if ($columns === []) {
            return null;
        }

        $columnTypes = array_fill_keys($columns, 'TEXT');
        $typedColumns = array_fill_keys(
            $columns,
            new ColumnType(ColumnTypeFamily::TEXT, 'TEXT'),
        );

        return new TableDefinition($columns, $columnTypes, [], [], [], $typedColumns);
    }

    private function tableBody(string $sql): ?string
    {
        $tablePrefix = '/^CREATE\s+(?:(?:TEMP|TEMPORARY)\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)\s*\(/is';
        if (preg_match($tablePrefix, $sql, $matches) !== 1) {
            return null;
        }

        $openingOffset = strlen($matches[0]) - 1;
        $closing = null;
        foreach (SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens() as $token) {
            if ($token->isTopLevel()
                && $token->kind === SqlTokenKind::Symbol
                && $token->text === ')'
            ) {
                $closing = $token;
                break;
            }
        }
        if ($closing === null) {
            return null;
        }

        $suffix = substr($sql, $closing->endOffset());
        if (!self::hasValidTableOptions($suffix)) {
            return null;
        }

        return substr($sql, $openingOffset + 1, $closing->offset - $openingOffset - 1);
    }

    private static function hasValidTableOptions(string $suffix): bool
    {
        $tokens = SqlTokenStream::tokenize($suffix, SqliteLexerProfile::create())->significantTokens();
        $last = $tokens[count($tokens) - 1] ?? null;
        if ($last !== null
            && $last->kind === SqlTokenKind::Symbol
            && $last->text === ';'
        ) {
            array_pop($tokens);
        }
        if ($tokens === []) {
            return true;
        }

        $seen = [];
        $index = 0;
        while ($index < count($tokens)) {
            if ($tokens[$index]->isKeyword('STRICT')) {
                $option = 'STRICT';
                $index++;
            } elseif ($tokens[$index]->isKeyword('WITHOUT')
                && ($tokens[$index + 1] ?? null)?->isKeyword('ROWID') === true
            ) {
                $option = 'WITHOUT ROWID';
                $index += 2;
            } else {
                return false;
            }

            if (isset($seen[$option])) {
                return false;
            }
            $seen[$option] = $option;

            if ($index === count($tokens)) {
                return true;
            }
            if ($tokens[$index]->kind !== SqlTokenKind::Symbol
                || $tokens[$index]->text !== ','
            ) {
                return false;
            }
            $index++;
        }

        return false;
    }

    private static function hasWithoutRowid(string $sql): bool
    {
        $withoutClause = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->topLevelClause(['WITHOUT']);
        if ($withoutClause === null) {
            return false;
        }

        return SqlTokenStream::tokenize($withoutClause, SqliteLexerProfile::create())->firstTopLevelKeyword() === 'ROWID';
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
     * @return array{name: string, type: string|null, notNull: bool, primaryKey: bool, unique: bool, default: string|null, generatedExpression: string|null}|null
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
        $leadingKeyword = $this->leadingKeyword($rest);

        $type = null;
        if (!in_array($leadingKeyword, [
            'PRIMARY',
            'NOT',
            'UNIQUE',
            'CHECK',
            'DEFAULT',
            'REFERENCES',
            'CONSTRAINT',
            'COLLATE',
            'GENERATED',
            'AS',
        ], true)) {
            $type = $this->extractColumnType($rest);
        }

        $upperDef = strtoupper($def);
        $notNull = str_contains($upperDef, 'NOT NULL');
        $primaryKey = (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $def);
        $unique = (bool) preg_match('/\bUNIQUE\b/i', $def) && !$primaryKey;
        $default = SqlTokenStream::tokenize($rest, SqliteLexerProfile::create())->topLevelClause(
            ['DEFAULT'],
            [
                ['PRIMARY', 'KEY'], ['NOT', 'NULL'], ['UNIQUE'], ['CHECK'],
                ['REFERENCES'], ['COLLATE'], ['CONSTRAINT'], ['GENERATED'], ['AS'],
            ],
        );
        $stream = SqlTokenStream::tokenize($rest, SqliteLexerProfile::create());
        $generatedExpression = $stream->topLevelClause(
            ['AS'],
            [['STORED'], ['VIRTUAL']],
        );
        if ($generatedExpression === '') {
            $generatedExpression = null;
        }

        return [
            'name' => $name,
            'type' => $type,
            'notNull' => $notNull,
            'primaryKey' => $primaryKey,
            'unique' => $unique,
            'default' => $default,
            'generatedExpression' => $generatedExpression,
        ];
    }

    private function leadingKeyword(string $sql): string
    {
        $length = strspn($sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$');
        return strtoupper(substr($sql, 0, $length));
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
     * Parse a comma-separated column name list.
     *
     * @return list<string>
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
