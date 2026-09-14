<?php

declare(strict_types=1);

namespace Tests\Unit\Rewrite\FullText;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Rewrite\FullText\FullTextColumns;

#[CoversClass(FullTextColumns::class)]
final class FullTextColumnsTest extends TestCase
{
    public function testTableColumnsMatchesCaseAndRejectsViews(): void
    {
        $columns = new FullTextColumns();
        self::assertSame(['title', 'body'], $columns->tableColumns('DOCS', ['docs' => ['columns' => ['title', 'body']]]));
        self::assertNull($columns->tableColumns('docs', ['docs' => ['viewSql' => 'SELECT 1']]));
        self::assertNull($columns->tableColumns('missing', ['docs' => ['columns' => ['body']]]));
    }

    public function testMatchingColumnRequiresAnUnambiguousColumn(): void
    {
        $columns = new FullTextColumns();
        self::assertSame(['Body'], $columns->matchingColumn('body', ['docs' => ['columns' => ['title', 'Body']], 'v' => ['viewSql' => 'SELECT 1']]));
        self::assertNull($columns->matchingColumn('body', ['first' => ['columns' => ['body']], 'second' => ['columns' => ['body']]]));
        self::assertNull($columns->matchingColumn('missing', ['docs' => ['columns' => ['body']]]));
    }

}
