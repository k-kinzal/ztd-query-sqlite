<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Value;

use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\ColumnTypeFamily;

/**
 * Maps portable and native column types to SQLite CAST types.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class CastTypeMapper
{
    /**
     * Maps portable and native column types to SQLite CAST types.
     */
    public function mapToCastType(ColumnDeclaration $type): string
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
    public function mapNativeTypeToCastType(string $nativeType): string
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
