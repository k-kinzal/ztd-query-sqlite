<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;

final class SqliteColumnTypeMapper
{
    public function map(string $nativeType): ColumnType
    {
        $normalized = strtoupper(trim($nativeType));

        $family = match (true) {
            in_array($normalized, ['BOOLEAN', 'BOOL'], true) => ColumnTypeFamily::BOOLEAN,
            $normalized === 'DATE' => ColumnTypeFamily::DATE,
            $normalized === 'TIME' => ColumnTypeFamily::TIME,
            $normalized === 'DATETIME' => ColumnTypeFamily::DATETIME,
            $normalized === 'TIMESTAMP' => ColumnTypeFamily::TIMESTAMP,
            $normalized === 'JSON' => ColumnTypeFamily::JSON,
            str_contains($normalized, 'INT') => ColumnTypeFamily::INTEGER,
            str_contains($normalized, 'CLOB'), str_contains($normalized, 'TEXT') => ColumnTypeFamily::TEXT,
            str_contains($normalized, 'CHAR') => ColumnTypeFamily::STRING,
            $normalized === '', str_contains($normalized, 'BLOB') => ColumnTypeFamily::BINARY,
            str_contains($normalized, 'REAL'), str_contains($normalized, 'FLOA'),
            str_contains($normalized, 'DOUB') => ColumnTypeFamily::FLOAT,
            default => ColumnTypeFamily::DECIMAL,
        };

        return new ColumnType($family, $nativeType);
    }
}
