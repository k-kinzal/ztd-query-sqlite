<?php

declare(strict_types=1);

namespace Tests\Unit\Schema\Key;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyEntryParser;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlTokenStream;

#[CoversClass(ForeignKeyEntryParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Schema\Key\ForeignKeyTokens::class)]
#[UsesClass(SqliteLexerProfile::class)]
final class ForeignKeyEntryParserTest extends TestCase
{
    public function testParseEntryReadsNamedConstraintAndActions(): void
    {
        $stream = SqlTokenStream::tokenize('CONSTRAINT fk FOREIGN KEY(user_id) REFERENCES main.users(id) ON DELETE CASCADE ON UPDATE SET NULL', SqliteLexerProfile::create());
        $entry = (new ForeignKeyEntryParser())->parseEntry($stream, 'fallback', null);
        self::assertNotNull($entry);
        self::assertSame('fk', $entry['name']);
        self::assertSame(['user_id'], $entry['foreignKey']->columns);
        self::assertSame('users', $entry['foreignKey']->referencedTable);
        self::assertSame(['id'], $entry['foreignKey']->referencedColumns);
        self::assertSame(\ZtdQuery\Schema\Key\ReferentialAction::Cascade, $entry['foreignKey']->onDelete);
        self::assertSame(\ZtdQuery\Schema\Key\ReferentialAction::SetNull, $entry['foreignKey']->onUpdate);
    }

    public function testParseEntryAcceptsInlineReferencesAndRejectsMissingRelations(): void
    {
        $parser = new ForeignKeyEntryParser();
        $inline = $parser->parseEntry(SqlTokenStream::tokenize('user_id INTEGER REFERENCES users', SqliteLexerProfile::create()), 'inline', 'user_id');
        self::assertNotNull($inline);
        self::assertSame(['user_id'], $inline['foreignKey']->columns);
        self::assertNull($parser->parseEntry(SqlTokenStream::tokenize('user_id INTEGER', SqliteLexerProfile::create()), 'inline', 'user_id'));
        self::assertNull($parser->parseEntry(SqlTokenStream::tokenize('user_id REFERENCES', SqliteLexerProfile::create()), 'inline', 'user_id'));
    }

    public function testForeignKeyColumnsExtractsLocalCompositeKey(): void
    {
        $stream = SqlTokenStream::tokenize('FOREIGN KEY(a, b) REFERENCES parent(a, b)', SqliteLexerProfile::create());
        $parser = new ForeignKeyEntryParser();
        self::assertSame(['a', 'b'], $parser->foreignKeyColumns($stream, $stream->significantTokens(), 7));
        self::assertSame([], $parser->foreignKeyColumns($stream, [], 0));
    }

    public function testReferencedRelationUnqualifiesTableAndReadsColumns(): void
    {
        $stream = SqlTokenStream::tokenize('main.parent(a, b)', SqliteLexerProfile::create());
        self::assertSame(['table' => 'parent', 'columns' => ['a', 'b']], (new ForeignKeyEntryParser())->referencedRelation($stream, $stream->significantTokens(), 0));
    }

    public function testIdentifierListRequiresAClosingGroup(): void
    {
        $parser = new ForeignKeyEntryParser();
        $stream = SqlTokenStream::tokenize('(a, "b name")', SqliteLexerProfile::create());
        self::assertSame(['a', 'b name'], $parser->identifierList($stream, $stream->significantTokens(), 0));
        $incomplete = SqlTokenStream::tokenize('(a, b', SqliteLexerProfile::create());
        self::assertSame([], $parser->identifierList($incomplete, $incomplete->significantTokens(), 0));
    }

    public function testActionDistinguishesSupportedReferentialActions(): void
    {
        $parser = new ForeignKeyEntryParser();
        self::assertSame(\ZtdQuery\Schema\Key\ReferentialAction::Restrict, $parser->action(SqlTokenStream::tokenize('ON DELETE RESTRICT', SqliteLexerProfile::create())->significantTokens(), 'DELETE'));
        self::assertSame(\ZtdQuery\Schema\Key\ReferentialAction::SetDefault, $parser->action(SqlTokenStream::tokenize('ON UPDATE SET DEFAULT', SqliteLexerProfile::create())->significantTokens(), 'UPDATE'));
        self::assertSame(\ZtdQuery\Schema\Key\ReferentialAction::NoAction, $parser->action(SqlTokenStream::tokenize('ON UPDATE SET', SqliteLexerProfile::create())->significantTokens(), 'UPDATE'));
        self::assertSame(\ZtdQuery\Schema\Key\ReferentialAction::NoAction, $parser->action([], 'DELETE'));
    }

}
