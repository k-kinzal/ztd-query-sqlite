<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteFullTextSearchRewriter;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\SqliteParser;

#[CoversClass(SqliteFullTextSearchRewriter::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteFullTextSearchRewriterTest extends TestCase
{
    public function testRewritesTableMatchAcrossEveryFtsColumn(): void
    {
        $result = (new SqliteFullTextSearchRewriter())->rewrite(
            "SELECT title FROM fts_articles WHERE fts_articles MATCH 'search'",
            ['fts_articles' => ['columns' => ['title', 'body'], 'rows' => []]],
        );

        self::assertSame(
            "SELECT title FROM fts_articles WHERE (INSTR(LOWER(COALESCE(CAST(\"title\" AS TEXT), '') "
            . "|| ' ' || COALESCE(CAST(\"body\" AS TEXT), '')), "
            . "LOWER(NULLIF(TRIM(CAST(('search') AS TEXT)), ''))) > 0)",
            $result,
        );
    }

    public function testRewritesColumnMatchAndTableEqualsWithSingleParameters(): void
    {
        $rewriter = new SqliteFullTextSearchRewriter();
        $column = $rewriter->rewrite(
            'SELECT title FROM fts_articles WHERE title MATCH ?',
            ['fts_articles' => ['columns' => ['title', 'body'], 'rows' => []]],
        );
        $table = $rewriter->rewrite(
            'SELECT title FROM fts_articles WHERE "fts_articles" = :query',
            ['fts_articles' => ['columns' => ['title', 'body'], 'rows' => []]],
        );

        self::assertSame(1, substr_count($column, '?'));
        self::assertStringContainsString('COALESCE(CAST("title" AS TEXT)', $column);
        self::assertStringNotContainsString('"body"', $column);
        self::assertSame(1, substr_count($table, ':query'));
        self::assertStringContainsString('COALESCE(CAST("body" AS TEXT)', $table);
    }

    #[DataProvider('providerUnchangedSql')]
    public function testLeavesUnknownAmbiguousAndNonQueryOperandsUntouched(string $sql): void
    {
        $rewriter = new SqliteFullTextSearchRewriter();
        $tables = [
            'fts_articles' => ['columns' => ['title', 'body'], 'rows' => []],
            'other' => ['columns' => ['title'], 'rows' => []],
        ];

        self::assertSame($sql, $rewriter->rewrite($sql, $tables));
    }

    /** @return iterable<string, array{string}> */
    public static function providerUnchangedSql(): iterable
    {
        yield 'unknown column' => ['SELECT * FROM fts_articles WHERE missing MATCH ?'];
        yield 'ambiguous column' => ['SELECT * FROM fts_articles, other WHERE title MATCH ?'];
        yield 'non-query operand' => ['SELECT * FROM fts_articles WHERE fts_articles MATCH 1'];
        yield 'ordinary equality' => ['SELECT * FROM fts_articles WHERE title = ?'];
        yield 'ordinary symbol operator' => ["SELECT * FROM fts_articles WHERE title + 'suffix'"];
        yield 'literal' => ["SELECT 'fts_articles MATCH search' FROM fts_articles"];
    }

    public function testRewritesMultipleExpressionsFromRightToLeft(): void
    {
        $result = (new SqliteFullTextSearchRewriter())->rewrite(
            "SELECT title FROM fts_articles WHERE title MATCH 'search' OR body MATCH 'needle'",
            ['fts_articles' => ['columns' => ['title', 'body'], 'rows' => []]],
        );

        self::assertSame(
            "SELECT title FROM fts_articles WHERE (INSTR(LOWER(COALESCE(CAST(\"title\" AS TEXT), '')), "
            . "LOWER(NULLIF(TRIM(CAST(('search') AS TEXT)), ''))) > 0) OR "
            . "(INSTR(LOWER(COALESCE(CAST(\"body\" AS TEXT), '')), "
            . "LOWER(NULLIF(TRIM(CAST(('needle') AS TEXT)), ''))) > 0)",
            $result,
        );
    }

    public function testSkipsAnUnknownExpressionBeforeAValidExpression(): void
    {
        $result = (new SqliteFullTextSearchRewriter())->rewrite(
            "SELECT title FROM fts_articles WHERE missing MATCH ? OR body MATCH 'needle'",
            ['fts_articles' => ['columns' => ['title', 'body'], 'rows' => []]],
        );

        self::assertSame(
            "SELECT title FROM fts_articles WHERE missing MATCH ? OR "
            . "(INSTR(LOWER(COALESCE(CAST(\"body\" AS TEXT), '')), "
            . "LOWER(NULLIF(TRIM(CAST(('needle') AS TEXT)), ''))) > 0)",
            $result,
        );
    }

    public function testFindsATableAndColumnAfterEarlierNonMatches(): void
    {
        $result = (new SqliteFullTextSearchRewriter())->rewrite(
            "SELECT title FROM target WHERE title MATCH 'needle'",
            [
                'other' => ['columns' => ['other_title'], 'rows' => []],
                'target' => ['columns' => ['id', 'title'], 'rows' => []],
            ],
        );

        self::assertSame(
            "SELECT title FROM target WHERE (INSTR(LOWER(COALESCE(CAST(\"title\" AS TEXT), '')), "
            . "LOWER(NULLIF(TRIM(CAST(('needle') AS TEXT)), ''))) > 0)",
            $result,
        );
    }

    public function testLeavesANonFtsSymbolAfterATableNameUntouched(): void
    {
        $sql = "SELECT * FROM fts_articles WHERE fts_articles + 'suffix'";

        self::assertSame(
            $sql,
            (new SqliteFullTextSearchRewriter())->rewrite(
                $sql,
                ['fts_articles' => ['columns' => ['title'], 'rows' => []]],
            ),
        );
    }

    public function testLeavesColumnEqualityUntouchedWithASingleTable(): void
    {
        $sql = 'SELECT * FROM fts_articles WHERE title = ?';

        self::assertSame(
            $sql,
            (new SqliteFullTextSearchRewriter())->rewrite(
                $sql,
                ['fts_articles' => ['columns' => ['title'], 'rows' => []]],
            ),
        );
    }

    public function testSkipsAnOperatorWithoutALeftOperandBeforeAValidExpression(): void
    {
        $result = (new SqliteFullTextSearchRewriter())->rewrite(
            "MATCH ? OR title MATCH 'needle'",
            ['fts_articles' => ['columns' => ['title'], 'rows' => []]],
        );

        self::assertStringStartsWith('MATCH ? OR (INSTR(', $result);
    }

    public function testFindsATableMatchAfterAnEarlierContext(): void
    {
        $result = (new SqliteFullTextSearchRewriter())->rewrite(
            "SELECT * FROM target WHERE target MATCH 'needle'",
            [
                'other' => ['columns' => ['other_title'], 'rows' => []],
                'target' => ['columns' => ['title'], 'rows' => []],
            ],
        );

        self::assertStringContainsString('COALESCE(CAST("title" AS TEXT)', $result);
        self::assertStringNotContainsString("target MATCH 'needle'", $result);
    }

    public function testRejectsANonIdentifierEvenWhenItsTextMatchesATableName(): void
    {
        $sql = "SELECT * FROM fts_articles WHERE :target MATCH 'needle'";

        self::assertSame(
            $sql,
            (new SqliteFullTextSearchRewriter())->rewrite(
                $sql,
                [':target' => ['columns' => ['title'], 'rows' => []]],
            ),
        );
    }
}
