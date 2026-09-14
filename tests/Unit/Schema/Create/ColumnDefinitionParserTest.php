<?php

declare(strict_types=1);

namespace Tests\Unit\Schema\Create;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Schema\Create\ColumnDefinitionParser;

#[CoversClass(ColumnDefinitionParser::class)]
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
final class ColumnDefinitionParserTest extends TestCase
{
    public function testParseColumnDefinitionPreservesColumnMetadata(): void
    {
        self::assertSame([
            'name' => 'full name', 'type' => 'TEXT', 'notNull' => true,
            'primaryKey' => false, 'unique' => true, 'default' => "'guest'", 'generatedExpression' => null,
        ], (new ColumnDefinitionParser())->parseColumnDefinition('"full name" TEXT NOT NULL UNIQUE DEFAULT \'guest\''));
        self::assertNull((new ColumnDefinitionParser())->parseColumnDefinition(''));
        self::assertNull((new ColumnDefinitionParser())->parseColumnDefinition('"" TEXT'));
    }

    public function testLeadingKeywordStopsAtPunctuation(): void
    {
        self::assertSame('CONSTRAINT', (new ColumnDefinitionParser())->leadingKeyword('constraint c'));
        self::assertSame('DECIMAL', (new ColumnDefinitionParser())->leadingKeyword('decimal(10,2)'));
        self::assertSame('', (new ColumnDefinitionParser())->leadingKeyword('"name"'));
    }

    public function testExtractColumnTypeRetainsPrecisionAndMultiwordNames(): void
    {
        $parser = new ColumnDefinitionParser();
        self::assertSame('DECIMAL(10, 2)', $parser->extractColumnType('decimal(10, 2) DEFAULT 0'));
        self::assertSame('DOUBLE PRECISION', $parser->extractColumnType('double precision NOT NULL'));
        self::assertNull($parser->extractColumnType('PRIMARY KEY'));
        self::assertNull($parser->extractColumnType(''));
    }

    public function testParseColumnNameListUnquotesNames(): void
    {
        self::assertSame(['id', 'full name', 'age'], (new ColumnDefinitionParser())->parseColumnNameList('id, "full name",, `age`'));
    }

    public function testDeclaredTypeDistinguishesOmittedTypeAndConstraints(): void
    {
        $parser = new ColumnDefinitionParser();
        self::assertSame('INTEGER', $parser->declaredType('INTEGER PRIMARY KEY'));
        self::assertNull($parser->declaredType('PRIMARY KEY'));
        self::assertNull($parser->declaredType('GENERATED ALWAYS AS (1)'));
        self::assertNull($parser->declaredType(''));
    }

}
