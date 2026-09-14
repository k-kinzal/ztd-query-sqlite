<?php

declare(strict_types=1);

namespace Tests\Unit\Schema\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Schema\View\SqliteViewDefinitionParser;
use ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser;

#[CoversClass(SqliteViewDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\Relation\RelationSourceParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::class)]
#[UsesClass(SqliteSelectRelationParser::class)]
final class SqliteViewDefinitionParserTest extends TestCase
{
    public function testFromQueryParsesAQueryUsingSqliteRelationRules(): void
    {
        $definition = (new SqliteViewDefinitionParser())->fromQuery(
            " SELECT u.id FROM main.[users] u JOIN roles r ON r.id = u.role_id; \n",
        );

        self::assertSame('SELECT u.id FROM main.[users] u JOIN roles r ON r.id = u.role_id', $definition->query);
        self::assertSame(['users', 'roles'], $definition->dependencies);
    }

    public function testFromCreateStatementExtractsTheQueryFromASqliteCreateViewStatement(): void
    {
        $definition = (new SqliteViewDefinitionParser())->fromCreateStatement(
            'CREATE TEMP VIEW [active_users] AS SELECT * FROM [users] WHERE active = 1;',
        );

        self::assertNotNull($definition);
        self::assertSame('SELECT * FROM [users] WHERE active = 1', $definition->query);
        self::assertNull((new SqliteViewDefinitionParser())->fromCreateStatement('CREATE VIEW invalid AS   '));
    }
}
