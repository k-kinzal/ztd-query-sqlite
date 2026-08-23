<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteColumnTypeMapper;
use ZtdQuery\Schema\ColumnTypeFamily;

#[CoversClass(SqliteColumnTypeMapper::class)]
final class SqliteColumnTypeMapperTest extends TestCase
{
    public function testMapsSchemaAndPdoNativeTypes(): void
    {
        $mapper = new SqliteColumnTypeMapper();

        self::assertSame(ColumnTypeFamily::INTEGER, $mapper->map('integer')->family);
        self::assertSame(ColumnTypeFamily::FLOAT, $mapper->map('DOUBLE')->family);
        self::assertSame(ColumnTypeFamily::STRING, $mapper->map('VARCHAR(32)')->family);
        self::assertSame(ColumnTypeFamily::TEXT, $mapper->map('CLOB')->family);
        self::assertSame(ColumnTypeFamily::BINARY, $mapper->map('BLOB')->family);
        self::assertSame(ColumnTypeFamily::DECIMAL, $mapper->map('null')->family);
    }

    public function testPreservesNativeType(): void
    {
        self::assertSame('varchar(32)', (new SqliteColumnTypeMapper())->map('varchar(32)')->nativeType);
    }

    public function testMapsEverySupportedDeclaredTypeAlias(): void
    {
        $families = array_merge(
            array_fill_keys(['INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'UNSIGNED BIG INT', 'INT2', 'INT8', 'CHARINT', 'FLOATING POINT'], ColumnTypeFamily::INTEGER),
            array_fill_keys(['REAL', 'DOUBLE', 'DOUBLE PRECISION', 'FLOAT'], ColumnTypeFamily::FLOAT),
            array_fill_keys(['DECIMAL', 'NUMERIC'], ColumnTypeFamily::DECIMAL),
            array_fill_keys(['BOOLEAN', 'BOOL'], ColumnTypeFamily::BOOLEAN),
            array_fill_keys(['TEXT', 'CLOB'], ColumnTypeFamily::TEXT),
            array_fill_keys(['CHAR', 'CHARACTER', 'VARCHAR', 'VARCHAR2', 'LONGVARCHAR', 'VARYING CHARACTER', 'NCHAR', 'NATIVE CHARACTER', 'NVARCHAR'], ColumnTypeFamily::STRING),
            array_fill_keys(['BLOB', 'LONGBLOB', ''], ColumnTypeFamily::BINARY),
            array_fill_keys(['DATE'], ColumnTypeFamily::DATE),
            array_fill_keys(['TIME'], ColumnTypeFamily::TIME),
            array_fill_keys(['DATETIME'], ColumnTypeFamily::DATETIME),
            array_fill_keys(['TIMESTAMP'], ColumnTypeFamily::TIMESTAMP),
            array_fill_keys(['JSON'], ColumnTypeFamily::JSON),
            array_fill_keys(['STRING', 'CUSTOM_DOMAIN'], ColumnTypeFamily::DECIMAL),
        );
        $mapper = new SqliteColumnTypeMapper();

        self::assertSame(
            array_values($families),
            array_map(static fn (string $nativeType): ColumnTypeFamily => $mapper->map($nativeType)->family, array_keys($families)),
        );
        self::assertSame(ColumnTypeFamily::STRING, $mapper->map(' varchar (32) ')->family);
        self::assertSame(ColumnTypeFamily::INTEGER, $mapper->map(' integer ')->family);
        self::assertSame(ColumnTypeFamily::BINARY, $mapper->map('   ')->family);
    }
}
