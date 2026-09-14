<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\FullText;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\FullText\MatchExpressionRewriter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(MatchExpressionRewriter::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\FullText\FullTextColumns::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
#[UsesClass(SqliteLexerProfile::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
final class MatchExpressionRewriterTest extends TestCase
{
    public function testExpressionEditProjectsAllDocumentColumns(): void
    {
        $stream = SqlTokenStream::tokenize("docs MATCH 'hello'", SqliteLexerProfile::create());
        $rewriter = new MatchExpressionRewriter(new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser(), new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        $edit = $rewriter->expressionEdit($stream, $stream->significantTokens()[1], ['docs' => ['columns' => ['title', 'body']]]);
        self::assertNotNull($edit);
        self::assertSame(0, $edit['start']);
        self::assertSame(18, $edit['end']);
        self::assertStringContainsString('COALESCE(CAST("title" AS TEXT)', $edit['replacement']);
        self::assertStringContainsString('COALESCE(CAST("body" AS TEXT)', $edit['replacement']);
        self::assertStringContainsString("CAST(('hello') AS TEXT)", $edit['replacement']);
    }

    public function testExpressionEditRejectsAnUnknownDocument(): void
    {
        $stream = SqlTokenStream::tokenize("unknown MATCH 'hello'", SqliteLexerProfile::create());
        $rewriter = new MatchExpressionRewriter(new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser(), new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter());
        self::assertNull($rewriter->expressionEdit($stream, $stream->significantTokens()[1], ['docs' => ['columns' => ['body']]]));
    }

    public function testIsIdentifierRejectsLiteralTokens(): void
    {
        self::assertTrue(MatchExpressionRewriter::isIdentifier(new SqlToken(SqlTokenKind::Word, 'body', 0, 0, 0)));
        self::assertTrue(MatchExpressionRewriter::isIdentifier(new SqlToken(SqlTokenKind::QuotedIdentifier, '"body"', 0, 0, 0)));
        self::assertFalse(MatchExpressionRewriter::isIdentifier(new SqlToken(SqlTokenKind::String, "'body'", 0, 0, 0)));
    }

    public function testIsQueryExpressionAcceptsOnlyStringOrParameter(): void
    {
        self::assertTrue(MatchExpressionRewriter::isQueryExpression(new SqlToken(SqlTokenKind::String, "'query'", 0, 0, 0)));
        self::assertTrue(MatchExpressionRewriter::isQueryExpression(new SqlToken(SqlTokenKind::Parameter, '?', 0, 0, 0)));
        self::assertFalse(MatchExpressionRewriter::isQueryExpression(new SqlToken(SqlTokenKind::Word, 'query', 0, 0, 0)));
    }

}
