<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteSelectRelationParser;
use ZtdQuery\Platform\Sqlite\SqliteViewDefinitionParser;

#[CoversClass(SqliteViewDefinitionParser::class)]
#[UsesClass(SqliteSelectRelationParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteViewDefinitionParserTest extends TestCase
{
    public function testParsesAQueryUsingSqliteRelationRules(): void
    {
        $definition = (new SqliteViewDefinitionParser())->fromQuery(
            " SELECT u.id FROM main.[users] u JOIN roles r ON r.id = u.role_id; \n",
        );

        self::assertSame('SELECT u.id FROM main.[users] u JOIN roles r ON r.id = u.role_id', $definition->query);
        self::assertSame(['users', 'roles'], $definition->dependencies);
    }

    public function testExtractsTheQueryFromASqliteCreateViewStatement(): void
    {
        $definition = (new SqliteViewDefinitionParser())->fromCreateStatement(
            'CREATE TEMP VIEW [active_users] AS SELECT * FROM [users] WHERE active = 1;',
        );

        self::assertNotNull($definition);
        self::assertSame('SELECT * FROM [users] WHERE active = 1', $definition->query);
        self::assertNull((new SqliteViewDefinitionParser())->fromCreateStatement('CREATE VIEW invalid AS   '));
    }
}
