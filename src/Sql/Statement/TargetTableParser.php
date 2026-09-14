<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Statement;

/**
 * Resolves the target relation of a SQLite data or schema statement.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class TargetTableParser
{
    /**
     * Extract the target table name from a DML statement.
     */
    public function extractTargetTable(string $sql): ?string
    {
        $type = (new StatementClassifier())->classifyStatement($sql);
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
     * Resolves the target relation of a SQLite data or schema statement.
     */
    public function extractInsertTable(string $sql): ?string
    {
        $sql = (new StatementStructure())->statementTail($sql, ['INSERT', 'REPLACE']);
        if (preg_match('/\bINTO\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)/i', $sql, $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($matches[1]);
        }

        if (preg_match('/^REPLACE\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)/i', trim($sql), $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    /**
     * Resolves the target relation of a SQLite data or schema statement.
     */
    public function extractUpdateTable(string $sql): ?string
    {
        $sql = (new StatementStructure())->statementTail($sql, ['UPDATE']);
        if (preg_match('/^UPDATE\s+(?:OR\s+(?:ROLLBACK|ABORT|REPLACE|FAIL|IGNORE)\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s,]+)/i', trim($sql), $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    /**
     * Resolves the target relation of a SQLite data or schema statement.
     */
    public function extractDeleteTable(string $sql): ?string
    {
        $sql = (new StatementStructure())->statementTail($sql, ['DELETE']);
        if (preg_match('/\bFROM\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s,]+)/i', $sql, $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    /**
     * Resolves the target relation of a SQLite data or schema statement.
     */
    public function extractCreateTableName(string $sql): ?string
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        if (preg_match('/^CREATE\s+(?:(?:TEMP|TEMPORARY)\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)/i', $sql, $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    /**
     * Resolves the target relation of a SQLite data or schema statement.
     */
    public function extractDropTableName(string $sql): ?string
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s;]+)/i', trim($sql), $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($matches[1]);
        }

        return null;
    }

    /**
     * Resolves the target relation of a SQLite data or schema statement.
     */
    public function extractAlterTableName(string $sql): ?string
    {
        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
        if (preg_match('/^ALTER\s+TABLE\s+("(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s]+)/i', trim($sql), $matches) === 1) {
            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($matches[1]);
        }

        return null;
    }
}
