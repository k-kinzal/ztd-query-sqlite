<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteLexerProfile;
use ZtdQuery\Platform\Sqlite\SqliteTransactionStatementParser;
use ZtdQuery\Shadow\ShadowStore;
use ZtdQuery\Shadow\ShadowTransactionManager;

#[CoversClass(SqliteTransactionStatementParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class SqliteTransactionStatementParserTest extends TestCase
{
    #[DataProvider('providerSqliteTransactionForms')]
    public function testParsesSqliteTransactionForms(string $sql): void
    {
        self::assertNotNull((new SqliteTransactionStatementParser())->parse($sql));
    }

    /** @return array<string, array{string}> */
    public static function providerSqliteTransactionForms(): array
    {
        return [
            'begin' => ['BEGIN;'],
            'begin transaction' => ['BEGIN TRANSACTION'],
            'begin deferred' => ['BEGIN DEFERRED'],
            'begin deferred transaction' => ['BEGIN DEFERRED TRANSACTION'],
            'begin immediate' => ['BEGIN IMMEDIATE'],
            'begin immediate transaction' => ['BEGIN IMMEDIATE TRANSACTION'],
            'begin exclusive' => ['BEGIN EXCLUSIVE'],
            'begin exclusive transaction' => ['BEGIN EXCLUSIVE TRANSACTION'],
            'commit' => ['COMMIT'],
            'commit transaction' => ['COMMIT TRANSACTION'],
            'end' => ['END'],
            'end transaction' => ['END TRANSACTION'],
            'rollback' => ['ROLLBACK'],
            'rollback transaction' => ['ROLLBACK TRANSACTION'],
            'savepoint' => ['SAVEPOINT `a``b`'],
            'rollback to' => ['ROLLBACK TO point'],
            'rollback to savepoint' => ['ROLLBACK TO SAVEPOINT point'],
            'release' => ['RELEASE point'],
            'release savepoint' => ['RELEASE SAVEPOINT point'],
        ];
    }

    public function testRejectsNonSqliteAndMalformedForms(): void
    {
        $parser = new SqliteTransactionStatementParser();

        self::assertNull($parser->parse(''));
        self::assertNull($parser->parse('START TRANSACTION'));
        self::assertNull($parser->parse('COMMIT WORK'));
        self::assertNull($parser->parse('SAVEPOINT point extra'));
        self::assertNull($parser->parse('SAVEPOINT `broken'));
    }

    public function testAppliesUnescapedSqliteSavepointName(): void
    {
        $store = new ShadowStore();
        $store->set('items', [['id' => 1]]);
        $transactions = new ShadowTransactionManager($store);
        $transactions->begin();
        $statement = (new SqliteTransactionStatementParser())->parse('SAVEPOINT `a``b`');
        self::assertNotNull($statement);
        $statement->apply($transactions);
        $store->insert('items', [['id' => 2]]);

        $transactions->rollBackTo('a`b');

        self::assertSame([['id' => 1]], $store->get('items'));
    }
}
