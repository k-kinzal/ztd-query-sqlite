<?php

declare(strict_types=1);

namespace Tests\Unit\Schema\Create;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Schema\Create\VirtualTableParser;

#[CoversClass(VirtualTableParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\AssignmentColumnParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\AssignmentParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Expression\ValueListParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Insert\InsertClauseParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\OpaqueSqlSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\QuotedSpan::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\RelationSourceParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\StatementClassifier::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\StatementStructure::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Statement\TargetTableParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Dml\Update\UpdateClauseParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class VirtualTableParserTest extends TestCase
{
    public function testParseFts5VirtualTableKeepsColumnsAndSkipsModuleOptions(): void
    {
        $parser = new VirtualTableParser();
        $definition = $parser->parseFts5VirtualTable("CREATE VIRTUAL TABLE docs USING fts5(title, body UNINDEXED, tokenize='unicode61')");
        self::assertNotNull($definition);
        self::assertSame(['title', 'body'], $definition->columns);
        self::assertSame(['title' => 'TEXT', 'body' => 'TEXT'], $definition->columnTypes);
        self::assertNull($parser->parseFts5VirtualTable("CREATE VIRTUAL TABLE docs USING fts5(tokenize='unicode61')"));
        self::assertNull($parser->parseFts5VirtualTable('CREATE VIRTUAL TABLE docs USING fts5(body INVALID)'));
        self::assertNull($parser->parseFts5VirtualTable('CREATE TABLE docs(body TEXT)'));
    }

    public function testBodyRequiresOneFts5ModuleAndCompleteFraming(): void
    {
        $parser = new VirtualTableParser();
        self::assertSame('title, body', $parser->body('CREATE VIRTUAL TABLE docs USING fts5(title, body);'));
        self::assertNull($parser->body('CREATE VIRTUAL TABLE docs USING fts4(body)'));
        self::assertNull($parser->body('CREATE VIRTUAL TABLE docs USING fts5(body) trailing'));
        self::assertNull($parser->body('CREATE VIRTUAL TABLE docs USING fts5(body'));
        self::assertNull($parser->body('CREATE VIRTUAL TABLE docs'));
        self::assertNull($parser->body('CREATE VIRTUAL TABLE docs USING fts5(body) USING fts5(x)'));
    }

}
