<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Dml\Update;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Dml\Update\UpdateClauseParser;

#[CoversClass(UpdateClauseParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
final class UpdateClauseParserTest extends TestCase
{
    public function testExtractOnConflictUpdateWhereExtractsOnConflictUpdateWhereAfterInsertSelectWhere(): void
    {
        $parser = new UpdateClauseParser();
        $sql = 'INSERT INTO items SELECT * FROM source WHERE active ON CONFLICT (id) DO UPDATE SET score = excluded.score WHERE items.score >= 80 RETURNING id';

        self::assertSame('items.score >= 80', $parser->extractOnConflictUpdateWhere($sql));
    }

    public function testExtractOnConflictUpdateWhereReturnsNullWithoutOnConflictUpdateWhere(): void
    {
        $parser = new UpdateClauseParser();

        self::assertNull($parser->extractOnConflictUpdateWhere(
            'INSERT INTO items VALUES (1, 90) ON CONFLICT (id) DO UPDATE SET score = excluded.score',
        ));
    }

    public function testExtractOnConflictUpdateWhereRequiresCompleteDoUpdateSetAnchor(): void
    {
        $parser = new UpdateClauseParser();

        self::assertNull($parser->extractOnConflictUpdateWhere(
            'INSERT INTO items VALUES (1, 90) ON CONFLICT (id) UPDATE SET score = excluded.score WHERE items.score >= 80',
        ));
    }

    public function testExtractUpdateAliasFromStructuredTarget(): void
    {
        $parser = new UpdateClauseParser();

        self::assertSame('target', $parser->extractUpdateAlias('UPDATE users AS target SET name = \'Alice\' WHERE target.id = 1'));
        self::assertSame('target', $parser->extractUpdateAlias('UPDATE users target SET name = \'Alice\' WHERE target.id = 1'));
        self::assertNull($parser->extractUpdateAlias('UPDATE users SET name = \'Alice\' WHERE id = 1'));
    }

    public function testExtractUpdateAliasHandlesConflictSchemaAndBracketedTarget(): void
    {
        $parser = new UpdateClauseParser();

        self::assertSame(
            'target',
            $parser->extractUpdateAlias(
                'UPDATE OR REPLACE main.[user table] AS target SET name = \'Alice\' WHERE target.id = 1',
            ),
        );
    }

    public function testExtractUpdateAliasHandlesEscapedBracketTargetWithoutAs(): void
    {
        $parser = new UpdateClauseParser();

        self::assertSame(
            'target',
            $parser->extractUpdateAlias(
                'UPDATE main.[user]]table] target SET name = \'Alice\' WHERE target.id = 1',
            ),
        );
        self::assertNull(
            $parser->extractUpdateAlias('UPDATE + ignored ] AS target SET name = \'Alice\''),
        );
    }

    public function testExtractUpdateAliasSkipsNestedUpdateKeyword(): void
    {
        $parser = new UpdateClauseParser();

        self::assertSame(
            'target',
            $parser->extractUpdateAlias(
                'WITH source AS (SELECT UPDATE FROM audit) UPDATE users AS target SET name = \'Alice\'',
            ),
        );
    }

    public function testExtractUpdateAliasRejectsIncompleteTargets(): void
    {
        $parser = new UpdateClauseParser();

        self::assertNull($parser->extractUpdateAlias('UPDATE'));
        self::assertNull($parser->extractUpdateAlias('UPDATE users'));
        self::assertNull($parser->extractUpdateAlias('UPDATE [users]'));
        self::assertNull($parser->extractUpdateAlias('UPDATE users target'));
    }

    public function testExtractUpdateFromClausePreservesJoinSource(): void
    {
        $parser = new UpdateClauseParser();

        self::assertSame(
            'incoming AS source',
            $parser->extractUpdateFromClause('UPDATE inventory SET quantity = source.quantity FROM incoming AS source WHERE inventory.id = source.id'),
        );
    }

    public function testExtractUpdateFromClauseStopsBeforeOrderedLimit(): void
    {
        $parser = new UpdateClauseParser();

        self::assertSame(
            'incoming AS source',
            $parser->extractUpdateFromClause(
                'UPDATE inventory SET quantity = source.quantity FROM incoming AS source ORDER BY source.id LIMIT 1',
            ),
        );
    }

    public function testExtractWhereClause(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id = 1', $parser->extractWhereClause('SELECT * FROM users WHERE id = 1'));
    }

    public function testExtractWhereClauseWithOrderBy(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 ORDER BY id'));
    }

    public function testExtractWhereClauseExtractNoWhereClause(): void
    {
        $parser = new UpdateClauseParser();
        self::assertNull($parser->extractWhereClause('SELECT * FROM users'));
    }

    public function testExtractWhereClauseWithGroupBy(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 GROUP BY name'));
    }

    public function testExtractWhereClauseWithHaving(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 HAVING count > 1'));
    }

    public function testExtractWhereClauseWithLimit(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('SELECT * FROM users WHERE id > 0 LIMIT 5'));
    }

    public function testExtractOrderByClause(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id DESC', $parser->extractOrderByClause('SELECT * FROM users ORDER BY id DESC'));
    }

    public function testExtractOrderByClauseWithLimit(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id', $parser->extractOrderByClause('SELECT * FROM users ORDER BY id LIMIT 5'));
    }

    public function testExtractOrderByClauseRequiresOrderBeforeBy(): void
    {
        $parser = new UpdateClauseParser();

        self::assertSame(
            'value',
            $parser->extractOrderByClause('SELECT value AS by FROM users ORDER BY value'),
        );
    }

    public function testExtractOrderByClauseNone(): void
    {
        $parser = new UpdateClauseParser();
        self::assertNull($parser->extractOrderByClause('SELECT * FROM users'));
    }

    public function testExtractLimitClause(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('10', $parser->extractLimitClause('SELECT * FROM users LIMIT 10'));
    }

    public function testExtractLimitClauseNone(): void
    {
        $parser = new UpdateClauseParser();
        self::assertNull($parser->extractLimitClause('SELECT * FROM users'));
    }

    public function testExtractWhereClauseLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id = 1', $parser->extractWhereClause('select * from users where id = 1'));
    }

    public function testExtractOrderByClauseLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id', $parser->extractOrderByClause('select * from users order by id'));
    }

    public function testExtractLimitClauseLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('10', $parser->extractLimitClause('select * from users limit 10'));
    }

    public function testExtractWhereClauseWithOrderByLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 order by id'));
    }

    public function testExtractWhereClauseWithLimitLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 limit 5'));
    }

    public function testExtractWhereClauseWithGroupByLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 group by name'));
    }

    public function testExtractWhereClauseWithHavingLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id > 0', $parser->extractWhereClause('select * from users where id > 0 having count > 1'));
    }

    public function testExtractOrderByClauseWithLimitLowercase(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('id', $parser->extractOrderByClause('select * from users order by id limit 5'));
    }

    public function testExtractWhereClauseWithOrderByV2(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractWhereClause('DELETE FROM t WHERE a > 1 ORDER BY a');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseWithLimitV2(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractWhereClause('DELETE FROM t WHERE a > 1 LIMIT 5');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseWithGroupByV2(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractWhereClause('SELECT * FROM t WHERE a > 1 GROUP BY a');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseWithHavingV2(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractWhereClause('SELECT * FROM t WHERE a > 1 HAVING COUNT(*) > 0');
        self::assertSame('a > 1', $result);
    }

    public function testExtractWhereClauseNone(): void
    {
        $parser = new UpdateClauseParser();
        self::assertNull($parser->extractWhereClause('SELECT * FROM t'));
    }

    public function testExtractOrderByClauseNoneV2(): void
    {
        $parser = new UpdateClauseParser();
        self::assertNull($parser->extractOrderByClause('SELECT * FROM t'));
    }

    public function testExtractOrderByClauseWithLimitV2(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('a', $parser->extractOrderByClause('SELECT * FROM t ORDER BY a LIMIT 5'));
    }

    public function testExtractLimitClauseNoneV2(): void
    {
        $parser = new UpdateClauseParser();
        self::assertNull($parser->extractLimitClause('SELECT * FROM t'));
    }

    public function testExtractLimitClausePresent(): void
    {
        $parser = new UpdateClauseParser();
        self::assertSame('10', $parser->extractLimitClause('SELECT * FROM t LIMIT 10'));
    }

    public function testExtractWhereClauseCaseInsensitive(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractWhereClause('select * from t where id = 1 order by id');
        self::assertSame('id = 1', $result);
    }

    public function testExtractOrderByClauseCaseInsensitive(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractOrderByClause('select * from t order by name limit 10');
        self::assertSame('name', $result);
    }

    public function testExtractLimitClauseCaseInsensitive(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractLimitClause('select * from t limit 5');
        self::assertSame('5', $result);
    }

    public function testExtractWhereClauseMultiline(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractWhereClause("SELECT * FROM t WHERE id = 1\nAND name = 'x' ORDER BY id");
        self::assertNotNull($result);
        self::assertStringContainsString('name', $result);
    }

    public function testExtractOrderByClauseMultiline(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractOrderByClause("SELECT * FROM t ORDER BY name,\nid LIMIT 10");
        self::assertNotNull($result);
        self::assertStringContainsString('id', $result);
    }

    public function testExtractLimitClauseMultiline(): void
    {
        $parser = new UpdateClauseParser();
        $result = $parser->extractLimitClause("SELECT * FROM t LIMIT 5\nOFFSET 10");
        self::assertNotNull($result);
        self::assertStringContainsString('5', $result);
    }
}
