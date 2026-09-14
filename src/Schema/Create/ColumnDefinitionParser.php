<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\Create;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Parses column declarations, defaults, types and generated expressions.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ColumnDefinitionParser
{
    /**
     * Parse a single column definition.
     *
     * @return array{name: string, type: string|null, notNull: bool, primaryKey: bool, unique: bool, default: string|null, generatedExpression: string|null}|null
     */
    public function parseColumnDefinition(string $def): ?array
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
        $type = $this->declaredType($rest);

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

    /**
     * Parses column declarations, defaults, types and generated expressions.
     */
    public function leadingKeyword(string $sql): string
    {
        $length = strspn($sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$');
        return strtoupper(substr($sql, 0, $length));
    }

    /**
     * Extract the column type from the rest of a column definition.
     */
    public function extractColumnType(string $rest): ?string
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
    public function parseColumnNameList(string $list): array
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

    /**
     * Returns the declared type before any column constraints.
     */
    public function declaredType(string $rest): ?string
    {
        $type = null;
        if (!in_array($this->leadingKeyword($rest), [
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

        return $type;
    }
}
