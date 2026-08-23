<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteIndexHintStripper;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;

#[CoversClass(SqliteIndexHintStripper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteIndexHintStripperTest extends TestCase
{
    public function testStripsIndexedByFromShadowSource(): void
    {
        self::assertSame(
            'SELECT * FROM products  WHERE category = ?',
            (new SqliteIndexHintStripper())->strip(
                'SELECT * FROM products INDEXED BY idx_category WHERE category = ?',
                ['products'],
            ),
        );
    }

    public function testStripsHintsAfterAliasesAndInNestedScopes(): void
    {
        self::assertSame(
            'SELECT * FROM products AS p  WHERE EXISTS (SELECT 1 FROM products nested )',
            (new SqliteIndexHintStripper())->strip(
                'SELECT * FROM products AS p INDEXED BY [idx_category] WHERE EXISTS (SELECT 1 FROM products nested NOT INDEXED)',
                ['products'],
            ),
        );
    }

    public function testPreservesHintsForPhysicalSources(): void
    {
        $sql = 'SELECT * FROM products INDEXED BY idx_category JOIN audit NOT INDEXED ON audit.id = products.id';

        self::assertSame($sql, (new SqliteIndexHintStripper())->strip($sql, ['users']));
    }

    public function testDoesNotTreatExpressionsAsSourceHints(): void
    {
        $sql = "SELECT 'INDEXED BY idx_category' FROM products WHERE note = 'NOT INDEXED'";

        self::assertSame($sql, (new SqliteIndexHintStripper())->strip($sql, ['products']));
    }

    public function testMatchesShadowNamesCaseInsensitivelyAfterPhysicalSource(): void
    {
        self::assertSame(
            'SELECT * FROM audit INDEXED BY audit_idx JOIN Products  ON Products.id = audit.id',
            (new SqliteIndexHintStripper())->strip(
                'SELECT * FROM audit INDEXED BY audit_idx JOIN Products INDEXED BY products_idx ON Products.id = audit.id',
                ['PRODUCTS'],
            ),
        );
    }

    public function testStripsEveryHintAcrossConsecutiveShadowSources(): void
    {
        self::assertSame(
            'SELECT * FROM products  JOIN products  ON TRUE',
            (new SqliteIndexHintStripper())->strip(
                'SELECT * FROM products NOT INDEXED JOIN products INDEXED BY `idx_products` ON TRUE',
                ['products'],
            ),
        );
    }

    #[DataProvider('providerIncompleteAndMalformedHints')]
    public function testPreservesIncompleteAndMalformedHints(string $sql): void
    {
        self::assertSame($sql, (new SqliteIndexHintStripper())->strip($sql, ['products']));
    }

    /** @return \Generator<string, array{string}> */
    public static function providerIncompleteAndMalformedHints(): \Generator
    {
        yield 'no hint' => ['SELECT * FROM products'];
        yield 'not without indexed' => ['SELECT * FROM products NOT'];
        yield 'not followed by clause' => ['SELECT * FROM products NOT WHERE TRUE'];
        yield 'indexed without by' => ['SELECT * FROM products INDEXED'];
        yield 'indexed followed by names' => ['SELECT * FROM products INDEXED wrong hint'];
        yield 'clause followed by by and name' => ['SELECT * FROM products WHERE BY hint'];
        yield 'symbol as index name' => ['SELECT * FROM products INDEXED BY ) ] WHERE TRUE'];
    }

    public function testDoesNotConfuseCommaBoundaryWithAlias(): void
    {
        self::assertSame(
            'SELECT * FROM products, users  WHERE TRUE',
            (new SqliteIndexHintStripper())->strip(
                'SELECT * FROM products, users INDEXED BY idx_users WHERE TRUE',
                ['products', 'users'],
            ),
        );
    }

    public function testStripsEmptyBracketIndexAfterBracketAlias(): void
    {
        self::assertSame(
            'SELECT * FROM products [p]  WHERE TRUE',
            (new SqliteIndexHintStripper())->strip(
                'SELECT * FROM products [p] INDEXED BY [] WHERE TRUE',
                ['products'],
            ),
        );
    }

    public function testIdentifierEndAcceptsBracketSymbolTokens(): void
    {
        $method = new \ReflectionMethod(SqliteIndexHintStripper::class, 'identifierEndIndex');
        $tokens = [
            new SqlToken(SqlTokenKind::Symbol, '[', 0, 0, 0),
            new SqlToken(SqlTokenKind::Symbol, ']', 1, 0, 0),
        ];

        self::assertSame(2, $method->invoke(null, $tokens, 0));
    }
}
