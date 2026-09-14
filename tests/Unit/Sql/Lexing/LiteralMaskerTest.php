<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Lexing;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker;

#[CoversClass(LiteralMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
final class LiteralMaskerTest extends TestCase
{
    public function testStripCommentsStripLineComment(): void
    {
        $parser = new LiteralMasker();
        $result = $parser->stripComments("SELECT 1 -- comment\nFROM t");
        self::assertStringContainsString('SELECT 1', $result);
        self::assertStringContainsString('FROM t', $result);
        self::assertStringNotContainsString('comment', $result);
    }

    public function testStripCommentsStripBlockComment(): void
    {
        $parser = new LiteralMasker();
        self::assertSame('SELECT 1   FROM t', $parser->stripComments('SELECT 1 /* comment */ FROM t'));
    }

    public function testStripCommentsStripBlockCommentPreservesLexicalBoundaryWithoutSurroundingSpaces(): void
    {
        $parser = new LiteralMasker();
        self::assertSame('SELECT FROM', $parser->stripComments('SELECT/* comment */FROM'));
    }

    public function testStripCommentsTrimsOuterWhitespace(): void
    {
        $parser = new LiteralMasker();
        self::assertSame('SELECT', $parser->stripComments(" \n/* comment */ SELECT \t"));
    }

    public function testStripCommentsPreservesQuotedCommentMarkers(): void
    {
        $parser = new LiteralMasker();
        $sql = "SELECT '/* value */', \"-- identifier\", `# identifier`, [/* identifier */] FROM users";
        self::assertSame($sql, $parser->stripComments($sql));
    }

    public function testMaskStringLiteralsStringLiteralMaskPreservesOffsetsAndQuotedIdentifiers(): void
    {
        $parser = new LiteralMasker();
        $sql = "SELECT 'FROM hidden', \"quoted\", `backtick`, [bracket] FROM items";
        $masked = $parser->maskStringLiterals($sql);
        self::assertSame(strlen($sql), strlen($masked));
        self::assertSame(strpos($sql, 'FROM items'), strpos($masked, 'FROM items'));
        self::assertStringContainsString('"quoted"', $masked);
        self::assertStringContainsString('`backtick`', $masked);
        self::assertStringContainsString('[bracket]', $masked);
    }

    #[DataProvider('providerStringLiteralMasks')]
    public function testMaskStringLiteralsUsesSqliteQuoteBoundaries(string $sql, string $expected): void
    {
        $parser = new LiteralMasker();
        $masked = $parser->maskStringLiterals($sql);
        self::assertSame($expected, $masked);
        self::assertSame(strlen($sql), strlen($masked));
    }

    public function testStripCommentsStripHashComment(): void
    {
        $parser = new LiteralMasker();
        $result = $parser->stripComments("SELECT 1 # comment\nFROM t");
        self::assertStringNotContainsString('#', $result);
        self::assertStringContainsString('SELECT 1', $result);
    }

    public function testStripCommentsPreservesContent(): void
    {
        $parser = new LiteralMasker();
        self::assertSame('SELECT 1', $parser->stripComments('SELECT 1'));
    }

    public function testStripCommentsStripMultipleComments(): void
    {
        $parser = new LiteralMasker();
        $result = $parser->stripComments("/* c1 */ SELECT /* c2 */ 1 -- c3\n");
        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('1', $result);
    }

    public function testStripCommentsRemovesBlockComment(): void
    {
        $parser = new LiteralMasker();
        $result = $parser->stripComments('SELECT /* comment */ 1');
        self::assertStringNotContainsString('/*', $result);
        self::assertStringNotContainsString('*/', $result);
    }

    public function testStripCommentsRemovesLineComment(): void
    {
        $parser = new LiteralMasker();
        $result = $parser->stripComments("SELECT 1 -- line comment\nFROM t");
        self::assertStringNotContainsString('--', $result);
    }

    public function testStripCommentsRemovesHashComment(): void
    {
        $parser = new LiteralMasker();
        $result = $parser->stripComments("SELECT 1 # hash comment\nFROM t");
        self::assertStringNotContainsString('#', $result);
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function providerStringLiteralMasks(): Generator
    {
        yield 'empty query' => ['', ''];
        yield 'query without strings' => ['SELECT value FROM items', 'SELECT value FROM items'];
        yield 'empty string' => ["''", '  '];
        yield 'single quoted string' => ["SELECT 'FROM hidden' FROM items", 'SELECT ' . str_repeat(' ', 13) . ' FROM items'];
        yield 'doubled single quote' => ["'value''FROM' FROM items", str_repeat(' ', 13) . ' FROM items'];
        yield 'doubled quote before closing quote' => ["'a''' FROM items", str_repeat(' ', 5) . ' FROM items'];
        yield 'unterminated string' => ["'FROM hidden", str_repeat(' ', 12)];
        yield 'multiple strings' => ["'FROM' || 'JOIN' FROM items", str_repeat(' ', 6) . ' || ' . str_repeat(' ', 6) . ' FROM items'];
        yield 'newline in string' => ["'FROM\nJOIN' FROM items", str_repeat(' ', 11) . ' FROM items'];
        yield 'double quoted identifier' => ['"FROM" FROM items', '"FROM" FROM items'];
        yield 'backtick identifier' => ['`FROM` FROM items', '`FROM` FROM items'];
        yield 'bracket identifier' => ['[FROM] FROM items', '[FROM] FROM items'];
        yield 'backslash does not escape quote' => ["'closed \\' FROM items", str_repeat(' ', 10) . ' FROM items'];
    }
}
