<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Connection\Exception\DatabaseException;
use ZtdQuery\Platform\Sqlite\SqliteErrorClassifier;

#[CoversClass(SqliteErrorClassifier::class)]
final class SqliteErrorClassifierTest extends TestCase
{
    public function testNoSuchTableIsUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('no such table: users', 1);
        self::assertTrue($classifier->isUnknownSchemaError($e));
    }

    public function testNoSuchColumnIsUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('no such column: name', 1);
        self::assertTrue($classifier->isUnknownSchemaError($e));
    }

    public function testHasNoColumnNamedIsUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('table users has no column named foo', 1);
        self::assertTrue($classifier->isUnknownSchemaError($e));
    }

    public function testSyntaxErrorIsNotUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('near "SELCT": syntax error', 1);
        self::assertFalse($classifier->isUnknownSchemaError($e));
    }

    public function testConstraintViolationIsNotUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('UNIQUE constraint failed: users.email', 19);
        self::assertFalse($classifier->isUnknownSchemaError($e));
    }

    public function testNullDriverCodeIsNotUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('some error', null);
        self::assertFalse($classifier->isUnknownSchemaError($e));
    }

    public function testNonSqliteErrorCodeIsNotUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('some error', 999);
        self::assertFalse($classifier->isUnknownSchemaError($e));
    }

    public function testUppercaseNoSuchTableIsUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('No Such Table: users', 1);
        self::assertTrue($classifier->isUnknownSchemaError($e));
    }

    public function testUppercaseNoSuchColumnIsUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('No Such Column: name', 1);
        self::assertTrue($classifier->isUnknownSchemaError($e));
    }

    public function testUppercaseHasNoColumnNamedIsUnknownSchemaError(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('Table users Has No Column Named foo', 1);
        self::assertTrue($classifier->isUnknownSchemaError($e));
    }

    public function testNullDriverCodeReturnsFalseNotNull(): void
    {
        $classifier = new SqliteErrorClassifier();
        $e = new DatabaseException('no such table: users', null);
        $result = $classifier->isUnknownSchemaError($e);
        self::assertFalse($result);
    }
}
