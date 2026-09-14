<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Upsert;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Upsert\ExpressionCursor;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(ExpressionCursor::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class ExpressionCursorTest extends TestCase
{
    public function testInitialPositionAndSourceArePreserved(): void
    {
        $tokens = SqlTokenStream::tokenize('1 + 2', SqliteLexerProfile::create())->significantTokens();
        $cursor = new ExpressionCursor('1 + 2', 'users', $tokens);
        self::assertSame(0, $cursor->index);
        self::assertSame('1 + 2', $cursor->sql);
        self::assertSame('users', $cursor->tableName);
        self::assertSame($tokens, $cursor->tokens);
    }

}
