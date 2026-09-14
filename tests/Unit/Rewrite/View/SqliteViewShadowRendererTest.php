<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\View\SqliteViewShadowRenderer;
use ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser;
use ZtdQuery\Schema\ViewDefinition;
use ZtdQuery\Schema\ViewDefinitionSet;

#[CoversClass(SqliteViewShadowRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\RelationSourceParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteSelectRelationParser::class)]
final class SqliteViewShadowRendererTest extends TestCase
{
    public function testRenderOrdersViewsAndUnqualifiesShadowedSqliteRelations(): void
    {
        $views = new ViewDefinitionSet();
        $views->register('summary', new ViewDefinition('SELECT count(*) FROM main.[active_users]', ['active_users']));
        $views->register('active_users', new ViewDefinition('SELECT * FROM main.[users]', ['users']));

        self::assertSame(
            [
                'active_users' => 'SELECT * FROM [users]',
                'summary' => 'SELECT count(*) FROM [active_users]',
            ],
            (new SqliteViewShadowRenderer())->render($views, ['users']),
        );
    }
}
