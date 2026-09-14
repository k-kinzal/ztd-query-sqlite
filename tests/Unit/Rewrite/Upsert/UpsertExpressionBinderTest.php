<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Upsert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertExpressionBinder;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(UpsertExpressionBinder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\SqlEdits::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Upsert\UpsertConflictPredicate::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class UpsertExpressionBinderTest extends TestCase
{
    public function testBindExpressionBindsIncomingExistingAndUnqualifiedColumns(): void
    {
        $binder = new UpsertExpressionBinder(['EXCLUDED'], new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        self::assertSame('"__ztd_incoming"."count" + "__ztd_existing"."count" + "__ztd_existing"."count"', $binder->bindExpression('EXCLUDED.count + users.count + count', 'users', ['count']));
        self::assertSame('upper("__ztd_existing"."name") || \'name\'', $binder->bindExpression("upper(name) || 'name'", 'users', ['name']));
        self::assertSame('other.count + (SELECT count FROM log)', $binder->bindExpression('other.count + (SELECT count FROM log)', 'users', ['count']));
        self::assertSame('"candidate"."count"', $binder->bindExpression('count', 'users', ['count'], 'candidate'));
    }

    public function testSubqueryTokenIndexesMarksOnlyNestedQueryTokens(): void
    {
        $binder = new UpsertExpressionBinder(['EXCLUDED'], new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        self::assertSame([1 => true, 2 => true, 3 => true, 4 => true], $binder->subqueryTokenIndexes(SqlTokenStream::tokenize('(SELECT count FROM log) + count', SqliteLexerProfile::create())->significantTokens()));
        self::assertSame([], $binder->subqueryTokenIndexes(SqlTokenStream::tokenize('SELECT count FROM log', SqliteLexerProfile::create())->significantTokens()));
    }

    public function testIsIdentifierDistinguishesColumnNames(): void
    {
        $binder = new UpsertExpressionBinder(['EXCLUDED'], new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        self::assertTrue($binder->isIdentifier(new SqlToken(SqlTokenKind::Word, 'name', 0, 0, 0)));
        self::assertFalse($binder->isIdentifier(new SqlToken(SqlTokenKind::String, "'name'", 0, 0, 0)));
    }

    public function testIdentifierUnquotesEscapedNames(): void
    {
        $binder = new UpsertExpressionBinder(['EXCLUDED'], new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        self::assertSame('a"b', $binder->identifier(new SqlToken(SqlTokenKind::QuotedIdentifier, '"a""b"', 0, 0, 0)));
        self::assertSame('name', $binder->identifier(new SqlToken(SqlTokenKind::Word, 'name', 0, 0, 0)));
    }

}
