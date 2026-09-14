<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\Transformer\Select;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\Select\ShadowCteRenderer;

#[CoversClass(ShadowCteRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\CastTypeMapper::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueExpressionRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\ValueLiteralRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer::class)]
final class ShadowCteRendererTest extends TestCase
{
    public function testGenerateCteRendersTypedFixturesExecutableBySqlite(): void
    {
        $renderer = new ShadowCteRenderer(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter(),
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer(),
        );
        $cte = $renderer->generateCte('users', [['id' => 7, 'name' => "O'Brien"], ['id' => 8, 'name' => null]], ['id', 'name'], [], []);
        $statement = (new PDO('sqlite::memory:'))->query('WITH ' . $cte . ' SELECT * FROM users ORDER BY id');
        self::assertNotFalse($statement);
        self::assertSame([['id' => 7, 'name' => "O'Brien"], ['id' => 8, 'name' => null]], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testGenerateCteRejectsEmptyUnknownSchemas(): void
    {
        $renderer = new ShadowCteRenderer(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter(),
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer(),
        );
        $this->expectException(RuntimeException::class);
        $renderer->generateCte('unknown', [], [], [], []);
    }

    public function testWrapCteQuotesTheProvidedTableAndProjectsGeneratedColumns(): void
    {
        $renderer = new ShadowCteRenderer(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter(),
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer(),
        );
        $cte = $renderer->wrapCte('"items"', 'SELECT 3 AS amount, NULL AS total', ['amount', 'total'], ['total' => '(amount * 2)']);
        $statement = (new PDO('sqlite::memory:'))->query('WITH ' . $cte . ' SELECT total FROM items');
        self::assertNotFalse($statement);
        self::assertSame(6, $statement->fetchColumn());
    }

    public function testRenderRowPreservesColumnOrderAndMissingValues(): void
    {
        $renderer = new ShadowCteRenderer(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter(),
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer(),
        );
        $sql = $renderer->renderRow(['id' => 9], ['name', 'id'], []);
        $statement = (new PDO('sqlite::memory:'))->query($sql);
        self::assertNotFalse($statement);
        self::assertSame([['name' => null, 'id' => 9]], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testRenderEmptyTableRetainsTypedZeroRowProjection(): void
    {
        $renderer = new ShadowCteRenderer(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter(),
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer(),
        );
        self::assertSame('SELECT CAST(NULL AS INTEGER) AS "id", CAST(NULL AS TEXT) AS "name" WHERE 0', $renderer->renderEmptyTable(['id', 'name'], ['id' => new \ZtdQuery\Schema\ColumnDeclaration(\ZtdQuery\Schema\ColumnTypeFamily::INTEGER, 'INTEGER')]));
    }

    public function testRenderFallbackNullCastUsesText(): void
    {
        $renderer = new ShadowCteRenderer(
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
            new \ZtdQuery\Platform\Sqlite\Rewrite\GeneratedColumn\SqliteGeneratedColumnProjector(),
            new \ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter(),
            new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteValueRenderer(),
        );
        self::assertSame('CAST(NULL AS TEXT)', $renderer->renderFallbackNullCast());
    }

}
