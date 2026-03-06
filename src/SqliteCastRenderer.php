<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;

/**
 * SQLite implementation of CastRenderer.
 *
 * Maps ColumnType to SQLite CAST syntax using SQLite's type affinity system.
 * SQLite supports: INTEGER, REAL, TEXT, BLOB, NUMERIC.
 */
final class SqliteCastRenderer implements CastRenderer
{
    public function renderCast(string $expression, ColumnType $type): string
    {
        $castType = $this->mapToCastType($type);

        return "CAST($expression AS $castType)";
    }

    public function renderNullCast(ColumnType $type): string
    {
        $castType = $this->mapToCastType($type);

        return "CAST(NULL AS $castType)";
    }

    private function mapToCastType(ColumnType $type): string
    {
        return match ($type->family) {
            ColumnTypeFamily::INTEGER => 'INTEGER',
            ColumnTypeFamily::DECIMAL => 'NUMERIC',
            ColumnTypeFamily::FLOAT, ColumnTypeFamily::DOUBLE => 'REAL',
            ColumnTypeFamily::BOOLEAN => 'INTEGER',
            ColumnTypeFamily::DATE, ColumnTypeFamily::TIME, ColumnTypeFamily::DATETIME, ColumnTypeFamily::TIMESTAMP => 'TEXT',
            ColumnTypeFamily::JSON => 'TEXT',
            ColumnTypeFamily::BINARY => 'BLOB',
            ColumnTypeFamily::STRING, ColumnTypeFamily::TEXT => 'TEXT',
            ColumnTypeFamily::UNKNOWN => $this->mapNativeTypeToCastType($type->nativeType),
        };
    }

    /**
     * Fallback mapping for UNKNOWN family using native type string.
     */
    private function mapNativeTypeToCastType(string $nativeType): string
    {
        $upperType = strtoupper($nativeType);
        $baseType = (string) preg_replace('/\(.*\)/', '', $upperType);

        return match ($baseType) {
            'INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'BOOLEAN', 'BOOL' => 'INTEGER',
            'REAL', 'DOUBLE', 'FLOAT' => 'REAL',
            'DECIMAL', 'NUMERIC' => 'NUMERIC',
            'BLOB' => 'BLOB',
            default => 'TEXT',
        };
    }
}
