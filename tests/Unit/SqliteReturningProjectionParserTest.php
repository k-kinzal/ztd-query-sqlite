<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteReturningProjectionParser;

#[CoversClass(SqliteReturningProjectionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteReturningProjectionParserTest extends TestCase
{
    public function testParsesSqliteQualifiedQuotedAliasesAndWildcard(): void
    {
        $projection = (new SqliteReturningProjectionParser())->parse(
            'UPDATE users SET name = \'x\' RETURNING users.id AS original_id, *, "name" AS display_name',
        );
        self::assertNotNull($projection);
        self::assertSame([
            ['source' => 'id', 'output' => 'original_id'],
            ['source' => null, 'output' => null],
            ['source' => 'name', 'output' => 'display_name'],
        ], $projection->items());

        self::assertSame([
            ['original_id' => 1, 'id' => 1, 'name' => 'Alice', 'display_name' => 'Alice'],
        ], $projection->project([['id' => 1, 'name' => 'Alice']]));
    }

    public function testUnquotesEscapedSqliteIdentifiersAndStatementTerminator(): void
    {
        $projection = (new SqliteReturningProjectionParser())->parse(
            'INSERT INTO users VALUES (1) RETURNING "source""name" AS `output``name`;',
        );
        self::assertNotNull($projection);

        self::assertSame([['output`name' => 'value']], $projection->project([['source"name' => 'value']]));
    }

    public function testReturnsNullWithoutSqliteReturningClause(): void
    {
        self::assertNull((new SqliteReturningProjectionParser())->parse('INSERT INTO users VALUES (1)'));
    }

    #[DataProvider('providerInvalidSqliteReturningProjection')]
    public function testRejectsMalformedSqliteProjection(string $projection): void
    {
        $this->expectException(UnsupportedSqlException::class);

        (new SqliteReturningProjectionParser())->parse('INSERT INTO users VALUES (1) RETURNING ' . $projection);
    }

    /** @return array<string, array{string}> */
    public static function providerInvalidSqliteReturningProjection(): array
    {
        return [
            'empty' => [''],
            'expression' => ['id + 1'],
            'missing alias' => ['id AS'],
            'invalid alias token' => ['id AS +'],
            'tokens after alias' => ['id AS alias extra'],
            'wildcard alias' => ['* AS all_columns'],
            'leading dot' => ['.id'],
            'trailing dot' => ['users.'],
            'double dot' => ['users..id'],
            'non-dot separator' => ['users + id'],
            'leading wildcard path' => ['*.id'],
            'non-trailing wildcard' => ['users.*.id'],
            'non-wildcard symbol path' => ['users.+'],
            'empty quoted identifier' => ['""'],
            'number token' => ['12'],
            'string token' => ["'id'"],
            'unterminated double quote' => ['"id'],
            'unterminated backtick' => ['`id'],
        ];
    }
}
