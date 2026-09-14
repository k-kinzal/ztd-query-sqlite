<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Transformer\Insert;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Insert\InsertProjectionBuilder;

#[CoversClass(InsertProjectionBuilder::class)]
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
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\CastTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexicalMasker::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer::class)]
final class InsertProjectionBuilderTest extends TestCase
{
    public function testBuildInsertSelectProjectsMultipleRowsWithGeneratedIdentity(): void
    {
        $identity = new \ZtdQuery\Rewrite\ShadowIdentityAllocator();
        $identity->beginProjection();
        $builder = new InsertProjectionBuilder(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            $identity,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer(),
        );
        $sql = $builder->buildInsertSelect("INSERT INTO users(name) VALUES ('Alice'), ('Bob')", 'users', ['id', 'name'], ['name'], [], [], ['id' => \ZtdQuery\Schema\Key\IdentityGenerationStrategy::MaxValue], [['id' => 7, 'name' => 'Existing']]);
        $statement = (new PDO('sqlite::memory:'))->query($sql);
        self::assertNotFalse($statement);
        self::assertSame([['id' => 8, 'name' => 'Alice'], ['id' => 9, 'name' => 'Bob']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBuildInsertSelectRejectsMismatchedRowCardinality(): void
    {
        $identity = new \ZtdQuery\Rewrite\ShadowIdentityAllocator();
        $identity->beginProjection();
        $builder = new InsertProjectionBuilder(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            $identity,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer(),
        );
        $this->expectException(\ZtdQuery\Exception\UnsupportedSqlException::class);
        $builder->buildInsertSelect('INSERT INTO users(id, name) VALUES (1)', 'users', ['id', 'name'], ['id', 'name'], [], [], [], []);
    }

    public function testBuildInsertRowSelectFillsDefaultsAndAppliesColumnTypes(): void
    {
        $identity = new \ZtdQuery\Rewrite\ShadowIdentityAllocator();
        $identity->beginProjection();
        $builder = new InsertProjectionBuilder(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            $identity,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer(),
        );
        $sql = $builder->buildInsertRowSelect(['7'], 'users', ['id', 'name'], ['id'], ['id' => new \ZtdQuery\Schema\ColumnDeclaration(\ZtdQuery\Schema\ColumnTypeFamily::INTEGER, 'INTEGER')], ['name' => "'guest'"], [], []);
        $statement = (new PDO('sqlite::memory:'))->query($sql);
        self::assertNotFalse($statement);
        self::assertSame([['id' => 7, 'name' => 'guest']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBuildSelectSourceProjectsAnInsertSelectSource(): void
    {
        $identity = new \ZtdQuery\Rewrite\ShadowIdentityAllocator();
        $identity->beginProjection();
        $builder = new InsertProjectionBuilder(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            $identity,
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertSelectRenderer(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertRowRenderer(),
        );
        $sql = $builder->buildSelectSource("INSERT INTO users(name) SELECT 'Alice'", 'users', ['id', 'name'], ['name'], [], ['id' => \ZtdQuery\Schema\Key\IdentityGenerationStrategy::MaxValue], [['id' => 3]]);
        $statement = (new PDO('sqlite::memory:'))->query($sql);
        self::assertNotFalse($statement);
        self::assertSame([['id' => 4, 'name' => 'Alice']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testOrderedValuesDiscardsKeysWithoutReorderingExpressions(): void
    {
        self::assertSame(['second', 'first'], InsertProjectionBuilder::orderedValues([2 => 'second', 1 => 'first']));
    }

}
