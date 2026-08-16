<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteLexicalMasker;

#[CoversClass(SqliteLexicalMasker::class)]
final class SqliteLexicalMaskerTest extends TestCase
{
    #[DataProvider('providerComments')]
    public function testMasksCommentsAsSingleLexicalSeparators(string $sql, string $expected): void
    {
        self::assertSame($expected, SqliteLexicalMasker::maskComments($sql));
    }

    #[DataProvider('providerQuotedForms')]
    public function testPreservesCommentMarkersInsideQuotedForms(string $quoted, bool $terminated): void
    {
        $sql = 'SELECT ' . $quoted . '/* outside */1';
        $expected = $terminated ? 'SELECT ' . $quoted . ' 1' : $sql;

        self::assertSame($expected, SqliteLexicalMasker::maskComments($sql));
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function providerComments(): \Generator
    {
        yield 'empty query' => ['', ''];
        yield 'line comment' => ['SELECT-- comment', 'SELECT '];
        yield 'line comment with newline' => ["SELECT-- comment\n1", "SELECT \n1"];
        yield 'line comment with carriage return' => ["SELECT-- comment\r1", "SELECT \r1"];
        yield 'empty line comment' => ["--\nSELECT", " \nSELECT"];
        yield 'separated minus signs' => ['SELECT- -1', 'SELECT- -1'];
        yield 'hash comment' => ['SELECT# comment', 'SELECT '];
        yield 'empty hash comment' => ["#\nSELECT", " \nSELECT"];
        yield 'block comment' => ['SELECT/* comment */1', 'SELECT 1'];
        yield 'minimal block comment' => ['SELECT/**/1', 'SELECT 1'];
        yield 'adjacent block comments' => ['SELECT/**//**/1', 'SELECT  1'];
        yield 'unterminated block comment' => ['SELECT/* comment', 'SELECT '];
        yield 'separated slash and asterisk' => ['SELECT/ *1', 'SELECT/ *1'];
        yield 'comment-like closing marker' => ['SELECT*/1', 'SELECT*/1'];
        yield 'comment closing boundary' => ['SELECT/**/*1', 'SELECT *1'];
        yield 'overlapping unterminated block marker' => ['SELECT/*/1', 'SELECT '];
    }

    /**
     * @return \Generator<string, array{string, bool}>
     */
    public static function providerQuotedForms(): \Generator
    {
        yield 'single quoted line marker' => ["'-- comment'", true];
        yield 'single quoted block marker' => ["'/* comment */'", true];
        yield 'empty single quote' => ["''", true];
        yield 'doubled single quote' => ["'value''/* comment */'", true];
        yield 'triple single quote closing run' => ["'value'''", true];
        yield 'unterminated single quote' => ["'/* comment", false];
        yield 'double quoted line marker' => ['"-- comment"', true];
        yield 'double quoted block marker' => ['"/* comment */"', true];
        yield 'empty double quote' => ['""', true];
        yield 'doubled double quote' => ['"value""/* comment */"', true];
        yield 'unterminated double quote' => ['"/* comment', false];
        yield 'backtick quoted hash marker' => ['`# comment`', true];
        yield 'empty backtick quote' => ['``', true];
        yield 'doubled backtick' => ['`value``/* comment */`', true];
        yield 'unterminated backtick' => ['`/* comment', false];
        yield 'bracket quoted line marker' => ['[-- comment]', true];
        yield 'bracket quoted block marker' => ['[/* comment */]', true];
        yield 'empty bracket quote' => ['[]', true];
        yield 'unterminated bracket' => ['[/* comment', false];
    }
}
