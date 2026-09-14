<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Statement;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Statement\TargetTableParser;

#[CoversClass(TargetTableParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\OpaqueSqlSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\RelationSourceParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\StatementClassifier::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\StatementStructure::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class TargetTableParserTest extends TestCase
{
    public function testExtractTargetTableExtractInsertTarget(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('INSERT INTO users (name) VALUES ("Alice")'));
    }

    public function testExtractTargetTableExtractInsertTargetQuoted(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO "my table" (name) VALUES ("Alice")'));
    }

    public function testExtractTargetTableExtractUpdateTarget(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE users SET name = "Bob"'));
    }

    public function testExtractTargetTableExtractDeleteTarget(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('DELETE FROM users WHERE id = 1'));
    }

    public function testExtractTargetTableExtractCreateTableTarget(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('CREATE TABLE users (id INTEGER)'));
    }

    public function testExtractTargetTableExtractDropTableTarget(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('DROP TABLE users'));
    }

    public function testExtractTargetTableExtractAlterTableTarget(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('ALTER TABLE users ADD COLUMN email TEXT'));
    }

    public function testExtractTargetTableExtractUpdateTargetAcrossBlockComment(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my_table', $parser->extractTargetTable('UPDATE/* table */my_table SET value = 1'));
    }

    public function testExtractTargetTableExtractDeleteTargetAcrossBlockComment(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my_table', $parser->extractTargetTable('DELETE FROM/* table */my_table WHERE id = 1'));
    }

    public function testExtractTargetTableExtractInsertTargetAcrossBlockComment(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my_table', $parser->extractTargetTable('INSERT INTO/* table */my_table VALUES (1)'));
    }

    public function testExtractTargetTableForSelect(): void
    {
        $parser = new TargetTableParser();
        self::assertNull($parser->extractTargetTable('SELECT * FROM users'));
    }

    public function testExtractTargetTableForUnsupported(): void
    {
        $parser = new TargetTableParser();
        self::assertNull($parser->extractTargetTable('CREATE INDEX idx ON users(name)'));
    }

    public function testExtractTargetTableExtractInsertTargetReplaceInto(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('REPLACE INTO users (id) VALUES (1)'));
    }

    public function testExtractTargetTableExtractInsertTargetWithBackticks(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO `my table` (id) VALUES (1)'));
    }

    public function testExtractTargetTableExtractInsertTargetWithBrackets(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO [my table] (id) VALUES (1)'));
    }

    public function testExtractTargetTableExtractUpdateTargetQuoted(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('UPDATE "my table" SET x = 1'));
    }

    public function testExtractTargetTableExtractUpdateTargetWithOrReplace(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR REPLACE users SET x = 1'));
    }

    public function testExtractTargetTableExtractDeleteTargetQuoted(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('DELETE FROM "my table" WHERE id = 1'));
    }

    public function testExtractTargetTableExtractCreateTableIfNotExists(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('CREATE TABLE IF NOT EXISTS users (id INT)'));
    }

    public function testExtractTargetTableExtractDropTableIfExists(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('DROP TABLE IF EXISTS users'));
    }

    public function testExtractTargetTableExtractInsertTargetLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable("insert into users (name) values ('Alice')"));
    }

    public function testExtractTargetTableExtractUpdateTargetLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable("update users set name = 'Bob'"));
    }

    public function testExtractTargetTableExtractDeleteTargetLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('delete from users where id = 1'));
    }

    public function testExtractTargetTableExtractCreateTableNameLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('create table users (id integer)'));
    }

    public function testExtractTargetTableExtractDropTableNameLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('drop table users'));
    }

    public function testExtractTargetTableExtractAlterTableNameLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('alter table users add column email text'));
    }

    public function testExtractTargetTableExtractUpdateTargetOrRollback(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR ROLLBACK users SET x = 1'));
    }

    public function testExtractTargetTableExtractUpdateTargetOrAbort(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR ABORT users SET x = 1'));
    }

    public function testExtractTargetTableExtractUpdateTargetOrFail(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR FAIL users SET x = 1'));
    }

    public function testExtractTargetTableExtractUpdateTargetOrIgnore(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('UPDATE OR IGNORE users SET x = 1'));
    }

    public function testExtractTargetTableCreateTableIfNotExistsLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('create table if not exists users (id integer)'));
    }

    public function testExtractTargetTableDropTableIfExistsLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('drop table if exists users'));
    }

    public function testExtractTargetTableExtractCreateTemporaryTableNameLowercase(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('tmp', $parser->extractTargetTable('create temporary table tmp (id integer)'));
    }

    public function testExtractTargetTableExtractReplaceTableTarget(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('REPLACE users (id) VALUES (1)'));
    }

    public function testExtractTargetTableSelect(): void
    {
        $parser = new TargetTableParser();
        self::assertNull($parser->extractTargetTable('SELECT * FROM users'));
    }

    public function testExtractTargetTableUnsupported(): void
    {
        $parser = new TargetTableParser();
        self::assertNull($parser->extractTargetTable('CREATE INDEX idx ON t (a)'));
    }

    public function testExtractTargetTableExtractInsertTableQuoted(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO "my table" (a) VALUES (1)'));
    }

    public function testExtractTargetTableExtractUpdateTableWithOrClause(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR ROLLBACK t SET a = 1'));
    }

    public function testExtractTargetTableExtractUpdateTableWithOrAbort(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR ABORT t SET a = 1'));
    }

    public function testExtractTargetTableExtractUpdateTableWithOrFail(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR FAIL t SET a = 1'));
    }

    public function testExtractTargetTableExtractUpdateTableWithOrIgnore(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR IGNORE t SET a = 1'));
    }

    public function testExtractTargetTableExtractUpdateTableWithOrReplace(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('UPDATE OR REPLACE t SET a = 1'));
    }

    public function testExtractTargetTableExtractDeleteTableQuoted(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('DELETE FROM "my table" WHERE id = 1'));
    }

    public function testExtractTargetTableExtractCreateTableNameWithIfNotExists(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('CREATE TABLE IF NOT EXISTS t (a INTEGER)'));
    }

    public function testExtractTargetTableExtractDropTableNameWithIfExists(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('DROP TABLE IF EXISTS t'));
    }

    public function testExtractTargetTableExtractAlterTableName(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('ALTER TABLE t ADD COLUMN a INTEGER'));
    }

    public function testExtractTargetTableExtractAlterTableNameQuoted(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('ALTER TABLE "my table" ADD COLUMN a INTEGER'));
    }

    public function testExtractTargetTableExtractReplaceTableName(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('REPLACE INTO t (a) VALUES (1)'));
    }

    public function testExtractTargetTableExtractReplaceTableNameWithoutInto(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('REPLACE t (a) VALUES (1)'));
    }

    public function testExtractTargetTableReturnsNullForUnclassifiable(): void
    {
        $parser = new TargetTableParser();
        self::assertNull($parser->extractTargetTable('PRAGMA table_info(users)'));
    }

    public function testExtractTargetTableExtractInsertTableFromReplace(): void
    {
        $parser = new TargetTableParser();
        $result = $parser->extractTargetTable('REPLACE INTO t VALUES (1)');
        self::assertSame('t', $result);
    }

    public function testExtractTargetTableExtractInsertTableFromReplaceNoInto(): void
    {
        $parser = new TargetTableParser();
        $result = $parser->extractTargetTable('REPLACE t VALUES (1)');
        self::assertSame('t', $result);
    }

    public function testExtractTargetTableExtractInsertTableCaseInsensitive(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('insert into t values (1)'));
    }

    public function testExtractTargetTableExtractDeleteTableCaseInsensitive(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('delete from t where id = 1'));
    }

    public function testExtractTargetTableExtractCreateTableName(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('CREATE TABLE users (id INTEGER)'));
    }

    public function testExtractTargetTableExtractDropTableName(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('users', $parser->extractTargetTable('DROP TABLE users'));
    }

    public function testExtractTargetTableExtractUpdateTableCaseInsensitive(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('update t set a = 1'));
    }

    public function testExtractTargetTableExtractInsertTableWithoutIntoReturnsNull(): void
    {
        $parser = new TargetTableParser();
        self::assertNull($parser->extractTargetTable('INSERT (a) VALUES (1)'));
    }

    public function testExtractTargetTableExtractInsertTableQuotedIdentifier(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('my table', $parser->extractTargetTable('INSERT INTO "my table" VALUES (1)'));
    }

    public function testExtractTargetTableExtractReplaceTableCaseInsensitive(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('replace into t values (1)'));
    }

    public function testExtractTargetTableExtractInsertTableFromReplaceWithoutInto(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('REPLACE t (a) VALUES (1)'));
    }

    public function testExtractTargetTableExtractReplaceCaseInsensitiveWithoutInto(): void
    {
        $parser = new TargetTableParser();
        self::assertSame('t', $parser->extractTargetTable('replace t values (1)'));
    }

    public function testExtractTargetTableUsesTheTopLevelDmlTailAfterCtesAndComments(): void
    {
        $parser = new TargetTableParser();

        self::assertSame(
            'insert_target',
            $parser->extractTargetTable(
                "WITH source AS (SELECT 'INTO decoy') /* boundary */ INSERT/* keyword */INTO insert_target VALUES (1)",
            ),
        );
        self::assertSame(
            'replace_target',
            $parser->extractTargetTable(
                "WITH source AS (SELECT 'INTO decoy') /* boundary */ REPLACE/* keyword */INTO replace_target VALUES (1)",
            ),
        );
        self::assertSame(
            'update_target',
            $parser->extractTargetTable(
                'WITH source AS (SELECT 1 AS UPDATE) /* boundary */ UPDATE/* keyword */update_target SET value = 1',
            ),
        );
        self::assertSame(
            'delete_target',
            $parser->extractTargetTable(
                'WITH source AS (SELECT * FROM decoy) /* boundary */ DELETE/* keyword */FROM delete_target',
            ),
        );
    }

    public function testExtractInsertTableDecodesQuotedTarget(): void
    {
        self::assertSame('users', (new TargetTableParser())->extractInsertTable('INSERT INTO "users" (id) VALUES (1)'));
        self::assertNull((new TargetTableParser())->extractInsertTable('SELECT 1'));
    }

    public function testExtractUpdateTableDecodesQuotedTarget(): void
    {
        self::assertSame('users', (new TargetTableParser())->extractUpdateTable('UPDATE OR IGNORE "users" SET id = 1'));
        self::assertNull((new TargetTableParser())->extractUpdateTable('SELECT 1'));
    }

    public function testExtractDeleteTableDecodesQuotedTarget(): void
    {
        self::assertSame('users', (new TargetTableParser())->extractDeleteTable('DELETE FROM "users" WHERE id = 1'));
        self::assertNull((new TargetTableParser())->extractDeleteTable('SELECT 1'));
    }

    public function testExtractCreateTableNameDecodesQuotedTarget(): void
    {
        self::assertSame('users', (new TargetTableParser())->extractCreateTableName('CREATE TEMP TABLE IF NOT EXISTS "users" (id INTEGER)'));
        self::assertNull((new TargetTableParser())->extractCreateTableName('SELECT 1'));
    }

    public function testExtractDropTableNameDecodesQuotedTarget(): void
    {
        self::assertSame('users', (new TargetTableParser())->extractDropTableName('DROP TABLE IF EXISTS "users";'));
        self::assertNull((new TargetTableParser())->extractDropTableName('SELECT 1'));
    }

    public function testExtractAlterTableNameDecodesQuotedTarget(): void
    {
        self::assertSame('users', (new TargetTableParser())->extractAlterTableName('ALTER TABLE "users" ADD name TEXT'));
        self::assertNull((new TargetTableParser())->extractAlterTableName('SELECT 1'));
    }

}
