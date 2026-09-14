<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\Create;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Schema\TableDefinition;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Parses FTS5 virtual-table declarations and column modifiers.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class VirtualTableParser
{
    /**
     * Parses FTS5 virtual-table declarations and column modifiers.
     */
    public function parseFts5VirtualTable(string $sql): ?TableDefinition
    {
        $body = $this->body($sql);
        if ($body === null) {
            return null;
        }
        $columns = [];
        $parser = new SqliteParser();
        foreach (SqlTokenStream::tokenize($body, SqliteLexerProfile::create())->splitTopLevel() as $definition) {
            $definitionTokens = SqlTokenStream::tokenize($definition, SqliteLexerProfile::create())->significantTokens();
            $name = $definitionTokens[0] ?? null;
            $assignment = $definitionTokens[1] ?? null;
            if ($assignment !== null
                && $assignment->kind === SqlTokenKind::Symbol
                && $assignment->text === '='
            ) {
                continue;
            }
            if ($name === null
                || !in_array($name->kind, [SqlTokenKind::Word, SqlTokenKind::QuotedIdentifier], true)
            ) {
                return null;
            }
            if (count($definitionTokens) > 2) {
                return null;
            }
            $modifier = $definitionTokens[1] ?? null;
            if ($modifier !== null && !$modifier->isKeyword('UNINDEXED')) {
                return null;
            }

            $columns[] = $parser->unquoteIdentifier($name->text);
        }
        if ($columns === []) {
            return null;
        }

        $columnTypes = array_fill_keys($columns, 'TEXT');
        $typedColumns = array_fill_keys(
            $columns,
            new ColumnDeclaration(ColumnTypeFamily::TEXT, 'TEXT'),
        );

        return new TableDefinition($columns, $columnTypes, [], [], [], $typedColumns);
    }

    /**
     * Returns the FTS5 declaration body when framing and trailing syntax are valid.
     */
    public function body(string $sql): ?string
    {
        $stream = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create());
        $tokens = $stream->significantTokens();
        if (($tokens[0] ?? null)?->isKeyword('CREATE') !== true
            || ($tokens[1] ?? null)?->isKeyword('VIRTUAL') !== true
            || ($tokens[2] ?? null)?->isKeyword('TABLE') !== true
        ) {
            return null;
        }

        $using = null;
        foreach ($tokens as $token) {
            if (!$token->isTopLevel() || !$token->isKeyword('USING')) {
                continue;
            }
            if ($using !== null) {
                return null;
            }
            $using = $token;
        }
        if ($using === null) {
            return null;
        }

        $module = $stream->significantTokenAfter($using);
        if ($module === null || !$module->isKeyword('FTS5')) {
            return null;
        }
        $opening = $stream->significantTokenAfter($module);
        if ($opening === null) {
            return null;
        }
        $closing = $stream->matchingClosingNestingToken($opening);
        if ($closing === null) {
            return null;
        }
        $suffix = trim(substr($sql, $closing->endOffset()));
        if ($suffix !== '' && $suffix !== ';') {
            return null;
        }

        return substr($sql, $opening->endOffset(), $closing->offset - $opening->endOffset());
    }
}
