<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteSelectRelationParser;
use ZtdQuery\Platform\Sqlite\SqliteViewShadowRenderer;
use ZtdQuery\Schema\ViewDefinition;
use ZtdQuery\Schema\ViewDefinitionSet;

#[CoversClass(SqliteViewShadowRenderer::class)]
#[UsesClass(SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteViewShadowRendererTest extends TestCase
{
    public function testOrdersViewsAndUnqualifiesShadowedSqliteRelations(): void
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
