<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Contract\SchemaParserContractTest;
use ZtdQuery\Platform\SchemaParser;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\SqliteSchemaParser;
use ZtdQuery\Schema\ColumnTypeFamily;

#[CoversClass(SqliteSchemaParser::class)]
#[UsesClass(SqliteParser::class)]
final class SqliteSchemaParserTest extends SchemaParserContractTest
{
    protected function createParser(): SchemaParser
    {
        return new SqliteSchemaParser();
    }

    protected function validCreateTableSql(): string
    {
        return 'CREATE TABLE users (id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, email TEXT, UNIQUE (email))';
    }

    protected function nonCreateTableSql(): string
    {
        return 'SELECT 1';
    }

    public function testParseSimpleCreateTable(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['id', 'name', 'email'], $result->columns);
        self::assertSame(['id'], $result->primaryKeys);
        self::assertContains('id', $result->notNullColumns);
        self::assertContains('name', $result->notNullColumns);
        self::assertSame('INTEGER', $result->columnTypes['id']);
        self::assertSame('TEXT', $result->columnTypes['name']);
        self::assertSame('TEXT', $result->columnTypes['email']);
    }

    public function testParseWithCompositePrimaryKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE order_items (order_id INTEGER, product_id INTEGER, quantity INTEGER, PRIMARY KEY (order_id, product_id))';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['order_id', 'product_id', 'quantity'], $result->columns);
        self::assertSame(['order_id', 'product_id'], $result->primaryKeys);
    }

    public function testParseWithUniqueConstraint(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, name TEXT)';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertArrayHasKey('email_UNIQUE', $result->uniqueConstraints);
        self::assertSame(['email'], $result->uniqueConstraints['email_UNIQUE']);
    }

    public function testParseWithTableLevelUniqueConstraint(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, UNIQUE (first_name, last_name))';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertNotEmpty($result->uniqueConstraints);
        $uniqueKeys = array_values($result->uniqueConstraints);
        self::assertSame(['first_name', 'last_name'], $uniqueKeys[0]);
    }

    public function testParseWithIfNotExists(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT)';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['id', 'name'], $result->columns);
    }

    public function testParseWithQuotedIdentifiers(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE "my table" ("my id" INTEGER PRIMARY KEY, "my name" TEXT)';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['my id', 'my name'], $result->columns);
    }

    public function testParseWithTypes(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b REAL, c TEXT, d BLOB, e NUMERIC, f VARCHAR(255), g DECIMAL(10,2))';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame('INTEGER', $result->columnTypes['a']);
        self::assertSame('REAL', $result->columnTypes['b']);
        self::assertSame('TEXT', $result->columnTypes['c']);
        self::assertSame('BLOB', $result->columnTypes['d']);
        self::assertSame('NUMERIC', $result->columnTypes['e']);
        self::assertSame('VARCHAR(255)', $result->columnTypes['f']);
        self::assertSame('DECIMAL(10,2)', $result->columnTypes['g']);
    }

    public function testParseWithDefaultValues(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (id INTEGER PRIMARY KEY, status TEXT DEFAULT 'active', created_at TEXT DEFAULT CURRENT_TIMESTAMP)";
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['id', 'status', 'created_at'], $result->columns);
    }

    public function testParseWithForeignKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id), total REAL)';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['id', 'user_id', 'total'], $result->columns);
    }

    public function testParseWithTableLevelForeignKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, FOREIGN KEY (user_id) REFERENCES users(id))';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['id', 'user_id'], $result->columns);
    }

    public function testParseNonCreateTableReturnsNull(): void
    {
        $parser = new SqliteSchemaParser();
        self::assertNull($parser->parse('SELECT * FROM users'));
        self::assertNull($parser->parse('INSERT INTO users (name) VALUES ("Alice")'));
        self::assertNull($parser->parse('DROP TABLE users'));
    }

    public function testParseMalformedReturnsNull(): void
    {
        $parser = new SqliteSchemaParser();
        self::assertNull($parser->parse('CREATE TABLE'));
        self::assertNull($parser->parse(''));
        self::assertNull($parser->parse('garbage text'));
    }

    public function testParseWithAutoincrement(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame(['id'], $result->primaryKeys);
    }

    public function testParseWithCheckConstraint(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE users (id INTEGER PRIMARY KEY, age INTEGER CHECK(age > 0), name TEXT)';
        $result = $parser->parse($sql);

        self::assertNotNull($result);
        self::assertSame(['id', 'age', 'name'], $result->columns);
    }

    /**
     * P-SP-1: primaryKeys is a subset of columns.
     */
    public function testPrimaryKeysSubsetOfColumns(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT, c REAL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame([], array_diff($result->primaryKeys, $result->columns));
    }

    /**
     * P-SP-2: Column types keys subset of columns.
     */
    public function testColumnTypeKeysSubsetOfColumns(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, c REAL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame([], array_diff(array_keys($result->columnTypes), $result->columns));
    }

    /**
     * P-SP-3: notNullColumns is a subset of columns.
     */
    public function testNotNullSubsetOfColumns(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER NOT NULL, b TEXT, c REAL NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame([], array_diff($result->notNullColumns, $result->columns));
    }

    /**
     * P-SP-4: Unique constraint columns subset of columns.
     */
    public function testUniqueConstraintColumnsSubsetOfColumns(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT UNIQUE, c REAL, UNIQUE(a, c))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        $allConstraintCols = array_unique(array_merge(...array_values($result->uniqueConstraints)));
        self::assertSame([], array_diff($allConstraintCols, $result->columns));
    }

    public function testParseTypedColumnsMapping(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INT, b TINYINT, c SMALLINT, d MEDIUMINT, e BIGINT, f INT2, g INT8)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['a']->family);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['b']->family);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['c']->family);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['d']->family);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['e']->family);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['f']->family);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['g']->family);
    }

    public function testParseTypedColumnsReal(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a REAL, b DOUBLE, c FLOAT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::FLOAT, $result->typedColumns['a']->family);
        self::assertSame(ColumnTypeFamily::FLOAT, $result->typedColumns['b']->family);
        self::assertSame(ColumnTypeFamily::FLOAT, $result->typedColumns['c']->family);
    }

    public function testParseTypedColumnsDoublePrecision(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a DOUBLE PRECISION)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::FLOAT, $result->typedColumns['a']->family);
    }

    public function testParseTypedColumnsDecimal(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a DECIMAL, b NUMERIC)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::DECIMAL, $result->typedColumns['a']->family);
        self::assertSame(ColumnTypeFamily::DECIMAL, $result->typedColumns['b']->family);
    }

    public function testParseTypedColumnsBoolean(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a BOOLEAN, b BOOL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::BOOLEAN, $result->typedColumns['a']->family);
        self::assertSame(ColumnTypeFamily::BOOLEAN, $result->typedColumns['b']->family);
    }

    public function testParseTypedColumnsText(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a TEXT, b CLOB)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::TEXT, $result->typedColumns['a']->family);
        self::assertSame(ColumnTypeFamily::TEXT, $result->typedColumns['b']->family);
    }

    public function testParseTypedColumnsString(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a CHAR, b CHARACTER, c VARCHAR, d NCHAR, e NVARCHAR)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['a']->family);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['b']->family);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['c']->family);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['d']->family);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['e']->family);
    }

    public function testParseTypedColumnsVaryingCharacter(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a VARYING CHARACTER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['a']->family);
    }

    public function testParseTypedColumnsNativeCharacter(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a NATIVE CHARACTER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['a']->family);
    }

    public function testParseTypedColumnsBlob(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a BLOB)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::BINARY, $result->typedColumns['a']->family);
    }

    public function testParseTypedColumnsDateTypes(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a DATE, b TIME, c DATETIME, d TIMESTAMP)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::DATE, $result->typedColumns['a']->family);
        self::assertSame(ColumnTypeFamily::TIME, $result->typedColumns['b']->family);
        self::assertSame(ColumnTypeFamily::DATETIME, $result->typedColumns['c']->family);
        self::assertSame(ColumnTypeFamily::TIMESTAMP, $result->typedColumns['d']->family);
    }

    public function testParseTypedColumnsJson(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a JSON)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::JSON, $result->typedColumns['a']->family);
    }

    public function testParseTypedColumnsUnknown(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a CUSTOM_TYPE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::UNKNOWN, $result->typedColumns['a']->family);
    }

    public function testParseTypedColumnsWithParenthesizedSize(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a VARCHAR(255))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['a']->family);
        self::assertSame('VARCHAR(255)', $result->typedColumns['a']->nativeType);
    }

    public function testParseWithConstraintPrimaryKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, CONSTRAINT pk_t PRIMARY KEY (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testParseWithConstraintUnique(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, CONSTRAINT uq_b UNIQUE (b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertNotEmpty($result->uniqueConstraints);
        $uniqueValues = array_values($result->uniqueConstraints);
        self::assertSame(['b'], $uniqueValues[0]);
    }

    public function testParseWithCheckConstraintAtTableLevel(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b INTEGER, CHECK (a > b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseNoColumnsReturnsNull(): void
    {
        $parser = new SqliteSchemaParser();
        self::assertNull($parser->parse('CREATE TABLE t ()'));
    }

    public function testParseWithoutRowid(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT) WITHOUT ROWID';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseColumnNoTypeWithNotNull(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->columns);
        self::assertContains('a', $result->notNullColumns);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testParseColumnNoTypeWithPrimaryKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a PRIMARY KEY)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testParseColumnWithDefault(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a TEXT DEFAULT 'hello', b INTEGER DEFAULT 0)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertSame('TEXT', $result->columnTypes['a']);
        self::assertSame('INTEGER', $result->columnTypes['b']);
    }

    public function testParseWithQuotedCommaInString(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a TEXT DEFAULT 'a,b', b INTEGER)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseColumnWithCollate(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a COLLATE NOCASE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->columns);
    }

    public function testParseColumnWithGeneratedAs(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b GENERATED ALWAYS AS (a * 2))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('b', $result->columns);
    }

    public function testParseColumnWithReferences(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER REFERENCES other(id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->columns);
    }

    public function testParsePrimaryKeyColumnIsNotNull(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a', $result->notNullColumns);
    }

    public function testParseUniqueColumnConstraint(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayHasKey('b_UNIQUE', $result->uniqueConstraints);
        self::assertSame(['b'], $result->uniqueConstraints['b_UNIQUE']);
    }

    public function testParseIntegerTypeFamilyMapping(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['a']->family);
    }

    public function testParseColumnWithBackticks(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (`my col` INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['my col'], $result->columns);
    }

    public function testParseColumnWithBrackets(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ([my col] INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['my col'], $result->columns);
    }

    public function testParseUniqueConstraintOnNonExistentColumnReturnsNull(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, UNIQUE(nonexistent))';
        $result = $parser->parse($sql);
        self::assertNull($result);
    }

    public function testParseTemporaryTable(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TEMPORARY TABLE tmp (id INTEGER PRIMARY KEY, val TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'val'], $result->columns);
    }

    public function testParseDuplicatePrimaryKeysDeduped(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, PRIMARY KEY (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testParseDuplicateNotNullDeduped(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        $count = array_count_values($result->notNullColumns);
        self::assertSame(1, $count['a']);
    }

    public function testParseLowercase(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table users (id integer primary key, name text not null)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'name'], $result->columns);
        self::assertSame(['id'], $result->primaryKeys);
        self::assertContains('name', $result->notNullColumns);
    }

    public function testParseLowercaseWithUnique(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (id integer primary key, email text unique)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertNotEmpty($result->uniqueConstraints);
    }

    public function testParseLowercaseTableLevelPrimaryKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (a integer, b integer, primary key (a, b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->primaryKeys);
    }

    public function testParseLowercaseTableLevelUnique(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (a integer, b integer, unique (a, b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertNotEmpty($result->uniqueConstraints);
    }

    public function testParseLowercaseConstraintPrimaryKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (a integer, constraint pk primary key (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testParseLowercaseConstraintUnique(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (a integer, constraint uq unique (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertNotEmpty($result->uniqueConstraints);
    }

    public function testParseLowercaseForeignKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (id integer, uid integer, foreign key (uid) references users(id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'uid'], $result->columns);
    }

    public function testParseLowercaseCheck(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (id integer, age integer, check (age > 0))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'age'], $result->columns);
    }

    public function testParseLowercaseIfNotExists(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table if not exists t (id integer)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id'], $result->columns);
    }

    public function testParseLowercaseTemporary(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create temporary table tmp (id integer)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id'], $result->columns);
    }

    public function testParseWithTrailingSemicolon(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER);';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id'], $result->columns);
    }

    public function testParseColumnNotNullAndUnique(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT NOT NULL UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('b', $result->notNullColumns);
        self::assertArrayHasKey('b_UNIQUE', $result->uniqueConstraints);
    }

    public function testParseColumnPrimaryKeyIsNotUnique(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
        self::assertEmpty($result->uniqueConstraints);
    }

    public function testParseLowercaseWithoutRowid(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT) without rowid';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseTypedColumnsNativeTypePreserved(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a TINYINT, b SMALLINT, c MEDIUMINT, d BIGINT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame('TINYINT', $result->typedColumns['a']->nativeType);
        self::assertSame('SMALLINT', $result->typedColumns['b']->nativeType);
        self::assertSame('MEDIUMINT', $result->typedColumns['c']->nativeType);
        self::assertSame('BIGINT', $result->typedColumns['d']->nativeType);
    }

    public function testParseColumnWithOnKeyword(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT ON CONFLICT REPLACE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('b', $result->columns);
    }

    public function testParseColumnTypeNotNullExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a NOT NULL, b INTEGER NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
        self::assertSame('INTEGER', $result->columnTypes['b']);
    }

    public function testParseColumnTypeUniqueExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testParseColumnTypeCheckExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a CHECK(a > 0))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testParseColumnTypeDefaultExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a DEFAULT 'x')";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testParseColumnTypeConstraintExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a CONSTRAINT ck CHECK(a > 0))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testParseColumnTypeAsExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b AS (a * 2))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('b', $result->columnTypes);
    }

    public function testParseWithWhitespace(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = '  CREATE TABLE t (a INTEGER)  ';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->columns);
    }

    public function testParsePrimaryKeyMergedFromMultipleSources(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b INTEGER, PRIMARY KEY (b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a', $result->primaryKeys);
        self::assertContains('b', $result->primaryKeys);
    }

    public function testParseConstraintQuotedName(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, CONSTRAINT "pk_t" PRIMARY KEY (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testParseConstraintBacktickName(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, CONSTRAINT `uq` UNIQUE (b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertNotEmpty($result->uniqueConstraints);
    }

    public function testParseMultipleTableLevelUniqueConstraints(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, c TEXT, UNIQUE(a, b), UNIQUE(b, c))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertCount(2, $result->uniqueConstraints);
    }

    public function testParseColumnTypeWithForeign(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a FOREIGN)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testParseColumnTypeFamilyInteger(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['a']->family);
        self::assertSame('INTEGER', $result->typedColumns['a']->nativeType);
    }

    public function testParseColumnTypeFamilyUnknownWithParens(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a CUSTOM_TYPE(10))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::UNKNOWN, $result->typedColumns['a']->family);
        self::assertSame('CUSTOM_TYPE(10)', $result->typedColumns['a']->nativeType);
    }

    public function testParseColumnWithSingleQuoteInDefault(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a TEXT DEFAULT 'it''s', b INTEGER)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseUniqueConstraintIncrementIndex(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT UNIQUE, c TEXT, UNIQUE(a), UNIQUE(c))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayHasKey('b_UNIQUE', $result->uniqueConstraints);
        self::assertArrayHasKey('unique_0', $result->uniqueConstraints);
        self::assertArrayHasKey('unique_1', $result->uniqueConstraints);
    }

    public function testParseEmptyDefinitionSkipped(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, , b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseSplitColumnDefinitionsWithQuotedComma(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a TEXT DEFAULT 'a,b', c INTEGER)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'c'], $result->columns);
    }

    public function testParseSplitColumnDefinitionsWithParenComma(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a DECIMAL(10,2), b INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertSame('DECIMAL(10,2)', $result->columnTypes['a']);
    }

    public function testParseColumnNameEmpty(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ("" INTEGER)';
        $result = $parser->parse($sql);
        self::assertNull($result);
    }

    public function testParseSplitColumnDefinitionsDoubleQuoteInDefault(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a TEXT DEFAULT 'ab\"cd', b INTEGER)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseSplitColumnDefinitionsWithEscapedSingleQuote(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a TEXT DEFAULT 'a''b', c INTEGER)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'c'], $result->columns);
    }

    public function testParseSplitColumnDefinitionsWithEscapedDoubleQuote(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ("a""b" INTEGER, c TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a"b', $result->columns);
        self::assertContains('c', $result->columns);
    }

    public function testParseSplitColumnDefinitionsCloseParenReducesDepth(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a DECIMAL(10), b INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseArrayValuesAndUniqueOnPrimaryKeys(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b INTEGER NOT NULL, PRIMARY KEY (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
        self::assertSame(['a', 'b'], $result->notNullColumns);
    }

    public function testParseExtractColumnTypeWithParenthesized(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a VARCHAR(100) NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame('VARCHAR(100)', $result->columnTypes['a']);
    }

    public function testParseExtractColumnTypeWithTwoWordType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a DOUBLE PRECISION NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame('DOUBLE PRECISION', $result->columnTypes['a']);
    }

    public function testParseColumnDefinitionEmptyRestNoType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (x)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['x'], $result->columns);
        self::assertArrayNotHasKey('x', $result->columnTypes);
    }

    public function testParseColumnTypePrimaryExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a PRIMARY KEY)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testParseExtractColumnTypeNullForEmptyType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame([], $result->columnTypes);
    }

    public function testParseColumnNameList(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, PRIMARY KEY ("a", `b`))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a', $result->primaryKeys);
        self::assertContains('b', $result->primaryKeys);
    }

    public function testParseTypeFamilyMappingPreservesParenthesizedType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INT(11))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::INTEGER, $result->typedColumns['a']->family);
        self::assertSame('INT(11)', $result->typedColumns['a']->nativeType);
    }

    public function testPrimaryKeyConstraintDoesNotCreateColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, name TEXT, PRIMARY KEY (id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'name'], $result->columns);
        self::assertContains('id', $result->primaryKeys);
    }

    public function testUniqueConstraintDoesNotCreateColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, email TEXT, UNIQUE (email))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'email'], $result->columns);
        self::assertArrayHasKey('unique_0', $result->uniqueConstraints);
        self::assertContains('email', $result->uniqueConstraints['unique_0']);
    }

    public function testConstraintPrimaryKeyDoesNotCreateColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, CONSTRAINT pk PRIMARY KEY (id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id'], $result->columns);
        self::assertContains('id', $result->primaryKeys);
    }

    public function testConstraintUniqueDoesNotCreateColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, email TEXT, CONSTRAINT uq UNIQUE (email))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'email'], $result->columns);
    }

    public function testForeignKeyDoesNotCreateColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, user_id INTEGER, FOREIGN KEY (user_id) REFERENCES users (id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'user_id'], $result->columns);
    }

    public function testCheckConstraintDoesNotCreateColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, age INTEGER, CHECK (age > 0))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'age'], $result->columns);
    }

    public function testColumnWithPrimaryKeyKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id PRIMARY KEY)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithNotNullKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithUniqueKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithCheckKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id CHECK (id > 0))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithDefaultKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (id DEFAULT 0)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithReferencesKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id REFERENCES other(id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithConstraintKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id CONSTRAINT ck CHECK (id > 0))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithCollateKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id COLLATE NOCASE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithGeneratedKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id GENERATED ALWAYS AS (1))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testColumnWithAsKeywordHasNullType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id AS (1 + 1))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testDuplicatePrimaryKeysDeduped(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY, PRIMARY KEY (id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id'], $result->primaryKeys);
    }

    public function testDuplicateNotNullDeduped(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER NOT NULL PRIMARY KEY)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        $count = count(array_filter($result->notNullColumns, static fn (string $c): bool => $c === 'id'));
        self::assertSame(1, $count);
    }

    public function testColumnTypeTextMapsToTextFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (name TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::TEXT, $result->typedColumns['name']->family);
    }

    public function testColumnTypeRealMapsToFloatFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (val REAL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::FLOAT, $result->typedColumns['val']->family);
    }

    public function testColumnTypeBlobMapsToBinaryFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (data BLOB)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::BINARY, $result->typedColumns['data']->family);
    }

    public function testColumnTypeDateMapsToDateFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (d DATE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::DATE, $result->typedColumns['d']->family);
    }

    public function testColumnTypeDatetimeMapsToDatetimeFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (dt DATETIME)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::DATETIME, $result->typedColumns['dt']->family);
    }

    public function testColumnTypeTimestampMapsToTimestampFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (ts TIMESTAMP)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::TIMESTAMP, $result->typedColumns['ts']->family);
    }

    public function testColumnTypeTimeMapsToTimeFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (t TIME)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::TIME, $result->typedColumns['t']->family);
    }

    public function testColumnTypeJsonMapsToJsonFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (j JSON)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::JSON, $result->typedColumns['j']->family);
    }

    public function testColumnTypeBooleanMapsToBooleanFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (b BOOLEAN)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::BOOLEAN, $result->typedColumns['b']->family);
    }

    public function testColumnTypeDecimalMapsToDecimalFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (d DECIMAL(10,2))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::DECIMAL, $result->typedColumns['d']->family);
    }

    public function testColumnTypeVarcharMapsToStringFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (s VARCHAR(255))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::STRING, $result->typedColumns['s']->family);
    }

    public function testColumnTypeUnknownMapsToUnknownFamily(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (x CUSTOM_TYPE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::UNKNOWN, $result->typedColumns['x']->family);
    }

    public function testColumnUniqueNotPrimaryKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (email TEXT UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayHasKey('email_UNIQUE', $result->uniqueConstraints);
        self::assertNotContains('email', $result->primaryKeys);
    }

    public function testColumnPrimaryKeyAndUniqueOnlyCountsAsPrimary(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->primaryKeys);
        self::assertArrayNotHasKey('id_UNIQUE', $result->uniqueConstraints);
    }

    public function testMultipleUniqueConstraintIndexes(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, a TEXT, b TEXT, UNIQUE (a), UNIQUE (b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayHasKey('unique_0', $result->uniqueConstraints);
        self::assertArrayHasKey('unique_1', $result->uniqueConstraints);
        self::assertContains('a', $result->uniqueConstraints['unique_0']);
        self::assertContains('b', $result->uniqueConstraints['unique_1']);
    }

    public function testUniqueConstraintReferringToNonExistentColumnReturnsNull(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, UNIQUE (nonexistent))';
        $result = $parser->parse($sql);
        self::assertNull($result);
    }

    public function testExtractColumnTypeWithOnKeyword(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER ON CONFLICT ABORT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame('INTEGER ON CONFLICT ABORT', $result->columnTypes['id']);
    }

    public function testExtractColumnTypeReturnsNullForEmptyMatch(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->columns);
        self::assertArrayNotHasKey('id', $result->columnTypes);
    }

    public function testWithoutRowidTable(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT) WITHOUT ROWID';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'name'], $result->columns);
    }

    public function testTemporaryTable(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TEMPORARY TABLE t (id INTEGER PRIMARY KEY)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id'], $result->columns);
    }

    public function testIfNotExistsTable(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id'], $result->columns);
    }

    public function testColumnNotNullFlagSet(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER, name TEXT NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('name', $result->notNullColumns);
        self::assertNotContains('id', $result->notNullColumns);
    }

    public function testPrimaryKeyColumnIsNotNull(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (id INTEGER PRIMARY KEY)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('id', $result->notNullColumns);
    }

    public function testMapToColumnTypeFamilyParenthesizedType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (v NUMERIC(10))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::DECIMAL, $result->typedColumns['v']->family);
    }

    public function testPrimaryKeyConstraintBeforeColumnDefinitions(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, PRIMARY KEY (a), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertContains('a', $result->primaryKeys);
        self::assertSame('TEXT', $result->columnTypes['b']);
    }

    public function testConstraintPrimaryKeyBeforeColumnDefinitions(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, CONSTRAINT pk PRIMARY KEY (a), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertContains('a', $result->primaryKeys);
    }

    public function testConstraintUniqueBeforeColumnDefinitions(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, CONSTRAINT uq UNIQUE (a), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertArrayHasKey('unique_0', $result->uniqueConstraints);
    }

    public function testUniqueConstraintBeforeColumnDefinitions(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, UNIQUE (a), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testForeignKeyConstraintBeforeColumnDefinitions(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, FOREIGN KEY (a) REFERENCES other(id), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testCheckConstraintBeforeColumnDefinitions(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, CHECK (a > 0), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testParseColumnDefinitionReturnsNullForEmptyName(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ("" INTEGER)';
        $result = $parser->parse($sql);
        self::assertNull($result);
    }

    public function testSplitColumnDefinitionsWithQuotedComma(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ("a,b" INTEGER, c TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a,b', 'c'], $result->columns);
    }

    public function testSplitColumnDefinitionsWithSingleQuotedDefault(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (a TEXT DEFAULT 'x,y', b INTEGER)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testSplitColumnDefinitionsWithNestedParens(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER CHECK(a IN (1,2,3)), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
    }

    public function testExtractColumnTypeCaseInsensitive(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a integer)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame('INTEGER', $result->columnTypes['a']);
    }

    public function testExtractColumnTypeMultiWordType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a DOUBLE PRECISION)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::FLOAT, $result->typedColumns['a']->family);
    }

    public function testExtractColumnTypeWithParensAndKeyword(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a VARCHAR(255) NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame('VARCHAR(255)', $result->columnTypes['a']);
    }

    public function testParseLowercaseCreateTable(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'create table t (a integer primary key)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a', $result->primaryKeys);
    }

    public function testParseCaseInsensitivePrimaryKeyConstraint(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, primary key (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a', $result->primaryKeys);
    }

    public function testParseCaseInsensitiveUniqueConstraint(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, unique (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertCount(1, $result->uniqueConstraints);
    }

    public function testParseCaseInsensitiveForeignKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, foreign key (a) references other(id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->columns);
    }

    public function testParseCaseInsensitiveCheck(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, check (a > 0))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->columns);
    }

    public function testColumnWithNotNullHasFlag(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a TEXT NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a', $result->notNullColumns);
    }

    public function testColumnWithBoolCastPrimaryKey(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT UNIQUE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertContains('a', $result->primaryKeys);
        self::assertCount(1, $result->uniqueConstraints);
    }

    public function testParseColumnNameListWithQuotedNames(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ("a" INTEGER, "b" TEXT, PRIMARY KEY ("a", "b"))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->primaryKeys);
    }

    public function testParseColumnNameListTrimsWhitespace(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, PRIMARY KEY ( a , b ))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->primaryKeys);
    }

    public function testExtractColumnTypeOnKeywordInNonTypeList(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a ON DELETE CASCADE)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testExtractColumnTypeForeignKeywordExcluded(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a FOREIGN INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertArrayNotHasKey('a', $result->columnTypes);
    }

    public function testSplitColumnDefinitionsDoubleQuoteEscaped(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ("col""name" INTEGER, b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['col"name', 'b'], $result->columns);
    }

    public function testParseColumnNameListBacktickQuoted(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (`a` INTEGER, `b` TEXT, PRIMARY KEY (`a`))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testParseColumnNameListSkipsEmpty(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, UNIQUE (a, , b))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
    }

    public function testMultipleConstraintsProcessedInOrder(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, b TEXT, c REAL, PRIMARY KEY (a), UNIQUE (b), CHECK (c > 0), FOREIGN KEY (a) REFERENCES other(id))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b', 'c'], $result->columns);
        self::assertContains('a', $result->primaryKeys);
        self::assertCount(1, $result->uniqueConstraints);
    }

    public function testMapToColumnTypeFamilyAllIntegerTypes(): void
    {
        $parser = new SqliteSchemaParser();

        $result0 = $parser->parse('CREATE TABLE t0 (a INT)');
        self::assertNotNull($result0);
        self::assertSame(ColumnTypeFamily::INTEGER, $result0->typedColumns['a']->family);

        $result1 = $parser->parse('CREATE TABLE t1 (a TINYINT)');
        self::assertNotNull($result1);
        self::assertSame(ColumnTypeFamily::INTEGER, $result1->typedColumns['a']->family);

        $result2 = $parser->parse('CREATE TABLE t2 (a SMALLINT)');
        self::assertNotNull($result2);
        self::assertSame(ColumnTypeFamily::INTEGER, $result2->typedColumns['a']->family);

        $result3 = $parser->parse('CREATE TABLE t3 (a MEDIUMINT)');
        self::assertNotNull($result3);
        self::assertSame(ColumnTypeFamily::INTEGER, $result3->typedColumns['a']->family);

        $result4 = $parser->parse('CREATE TABLE t4 (a BIGINT)');
        self::assertNotNull($result4);
        self::assertSame(ColumnTypeFamily::INTEGER, $result4->typedColumns['a']->family);

        $result5 = $parser->parse('CREATE TABLE t5 (a INT2)');
        self::assertNotNull($result5);
        self::assertSame(ColumnTypeFamily::INTEGER, $result5->typedColumns['a']->family);

        $result6 = $parser->parse('CREATE TABLE t6 (a INT8)');
        self::assertNotNull($result6);
        self::assertSame(ColumnTypeFamily::INTEGER, $result6->typedColumns['a']->family);
    }

    public function testMapToColumnTypeFamilyAllStringTypes(): void
    {
        $parser = new SqliteSchemaParser();

        $result0 = $parser->parse('CREATE TABLE t0 (a CHAR)');
        self::assertNotNull($result0);
        self::assertSame(ColumnTypeFamily::STRING, $result0->typedColumns['a']->family);

        $result1 = $parser->parse('CREATE TABLE t1 (a CHARACTER)');
        self::assertNotNull($result1);
        self::assertSame(ColumnTypeFamily::STRING, $result1->typedColumns['a']->family);

        $result2 = $parser->parse('CREATE TABLE t2 (a VARCHAR)');
        self::assertNotNull($result2);
        self::assertSame(ColumnTypeFamily::STRING, $result2->typedColumns['a']->family);

        $result3 = $parser->parse('CREATE TABLE t3 (a NCHAR)');
        self::assertNotNull($result3);
        self::assertSame(ColumnTypeFamily::STRING, $result3->typedColumns['a']->family);

        $result4 = $parser->parse('CREATE TABLE t4 (a NVARCHAR)');
        self::assertNotNull($result4);
        self::assertSame(ColumnTypeFamily::STRING, $result4->typedColumns['a']->family);
    }

    public function testMapToColumnTypeFamilyClobIsText(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a CLOB)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::TEXT, $result->typedColumns['a']->family);
    }

    public function testMapToColumnTypeFamilyBlobIsBinary(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a BLOB)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::BINARY, $result->typedColumns['a']->family);
    }

    public function testMapToColumnTypeFamilyBooleanTypes(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a BOOL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::BOOLEAN, $result->typedColumns['a']->family);
    }

    public function testMapToColumnTypeFamilyDateTimeTypes(): void
    {
        $parser = new SqliteSchemaParser();

        $result0 = $parser->parse('CREATE TABLE t0 (a DATE)');
        self::assertNotNull($result0);
        self::assertSame(ColumnTypeFamily::DATE, $result0->typedColumns['a']->family);

        $result1 = $parser->parse('CREATE TABLE t1 (a TIME)');
        self::assertNotNull($result1);
        self::assertSame(ColumnTypeFamily::TIME, $result1->typedColumns['a']->family);

        $result2 = $parser->parse('CREATE TABLE t2 (a DATETIME)');
        self::assertNotNull($result2);
        self::assertSame(ColumnTypeFamily::DATETIME, $result2->typedColumns['a']->family);

        $result3 = $parser->parse('CREATE TABLE t3 (a TIMESTAMP)');
        self::assertNotNull($result3);
        self::assertSame(ColumnTypeFamily::TIMESTAMP, $result3->typedColumns['a']->family);
    }

    public function testMapToColumnTypeFamilyJsonType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a JSON)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::JSON, $result->typedColumns['a']->family);
    }

    public function testMapToColumnTypeFamilyUnknownType(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a FOOBAR)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(ColumnTypeFamily::UNKNOWN, $result->typedColumns['a']->family);
    }

    public function testUniqueConstraintWithEmptyColumnsNotStored(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, UNIQUE ())';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame([], $result->uniqueConstraints);
    }

    public function testColumnTypeRestIsTrimmed(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a   INTEGER  )';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame('INTEGER', $result->columnTypes['a']);
    }

    public function testPrimaryKeysArrayIsNumericallyIndexed(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT, c TEXT, PRIMARY KEY (a))';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame([0], array_keys($result->primaryKeys));
    }

    public function testNotNullColumnsArrayIsNumericallyIndexed(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY, b TEXT NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame([0, 1], array_keys($result->notNullColumns));
    }

    public function testNotNullColumnsDeduplicated(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER PRIMARY KEY NOT NULL, b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a'], $result->notNullColumns);
    }

    public function testConstraintFollowedByColumnParsesAll(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, FOREIGN KEY (a) REFERENCES other(id), b TEXT NOT NULL)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertContains('b', $result->notNullColumns);
    }

    public function testConstraintNamedPrimaryKeyFollowedByColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, CONSTRAINT pk PRIMARY KEY (a), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertSame(['a'], $result->primaryKeys);
    }

    public function testConstraintNamedUniqueFollowedByColumn(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t (a INTEGER, CONSTRAINT uq UNIQUE (a), b TEXT)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['a', 'b'], $result->columns);
        self::assertNotEmpty($result->uniqueConstraints);
    }

    public function testMultilineCreateTableParsed(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = "CREATE TABLE t (\n  id INTEGER PRIMARY KEY,\n  name TEXT NOT NULL\n)";
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['id', 'name'], $result->columns);
    }

    public function testParseReturnsNullForTrailingContent(): void
    {
        $parser = new SqliteSchemaParser();
        $result = $parser->parse('CREATE TABLE t (id INTEGER) EXTRA STUFF HERE');
        self::assertNull($result);
    }

    public function testParseColumnDefinitionWithQuotedColumnName(): void
    {
        $parser = new SqliteSchemaParser();
        $sql = 'CREATE TABLE t ("col one" TEXT, "col two" INTEGER)';
        $result = $parser->parse($sql);
        self::assertNotNull($result);
        self::assertSame(['col one', 'col two'], $result->columns);
        self::assertSame('TEXT', $result->columnTypes['col one']);
        self::assertSame('INTEGER', $result->columnTypes['col two']);
    }
}
