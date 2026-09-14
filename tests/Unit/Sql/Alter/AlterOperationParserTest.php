<?php

declare(strict_types=1);

namespace Tests\Unit\Sql\Alter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Sql\Alter\AlterOperationParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(AlterOperationParser::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class AlterOperationParserTest extends TestCase
{
    public function testAlterOperationDecodesSupportedChanges(): void
    {
        $parser = new AlterOperationParser();
        self::assertSame(['kind' => 'add', 'clause' => 'name TEXT'], $parser->alterOperation('ALTER TABLE users ADD COLUMN name TEXT;'));
        self::assertSame(['kind' => 'drop', 'clause' => 'name'], $parser->alterOperation('ALTER TABLE users DROP COLUMN name'));
        self::assertSame(['kind' => 'rename_table', 'clause' => 'people'], $parser->alterOperation('ALTER TABLE users RENAME TO people'));
        self::assertSame(['kind' => 'rename_column', 'clause' => 'name TO label'], $parser->alterOperation('ALTER TABLE users RENAME COLUMN name TO label'));
        self::assertNull($parser->alterOperation('ALTER TABLE users DROP name'));
        self::assertNull($parser->alterOperation('ALTER TABLE users'));
        self::assertNull($parser->alterOperation('ALTER TABLE'));
    }

    public function testAlterClauseExcludesTrailingSemicolon(): void
    {
        $sql = 'ALTER TABLE users ADD name TEXT;';
        $parser = new AlterOperationParser();
        self::assertSame(['kind' => 'add', 'clause' => 'name TEXT'], $parser->alterClause($sql, SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens(), 'add', 4));
        self::assertNull($parser->alterClause($sql, [], 'add', 0));
    }

    public function testSingleIdentifierRequiresNoTrailingSyntax(): void
    {
        $parser = new AlterOperationParser();
        self::assertSame('full name', $parser->singleIdentifier('"full name"'));
        self::assertNull($parser->singleIdentifier('name extra'));
        self::assertNull($parser->singleIdentifier(''));
    }

    public function testRenamedIdentifiersRequiresTwoNamesAndTo(): void
    {
        $parser = new AlterOperationParser();
        self::assertSame(['old name', 'new name'], $parser->renamedIdentifiers('"old name" TO "new name"'));
        self::assertNull($parser->renamedIdentifiers('old TO'));
        self::assertNull($parser->renamedIdentifiers('old FROM new'));
        self::assertNull($parser->renamedIdentifiers('old TO new extra'));
        self::assertNull($parser->renamedIdentifiers(''));
    }

}
