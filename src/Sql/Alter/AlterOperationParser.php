<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Alter;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Parses supported ALTER TABLE operation names and identifiers.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class AlterOperationParser
{
    /**
     * @return array{kind: 'add'|'drop'|'rename_table'|'rename_column', clause: string}|null
     */
    public function alterOperation(string $sql): ?array
    {
        $stream = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create());
        $tokens = $stream->significantTokens();
        $source = $stream->identifierAt(2);
        if ($source === null) {
            return null;
        }
        $operationIndex = $source['next'];
        $operation = $tokens[$operationIndex] ?? null;
        if ($operation === null) {
            return null;
        }
        if ($operation->isKeyword('ADD')) {
            $clauseIndex = $operationIndex + 1;
            $clauseStart = $tokens[$clauseIndex] ?? null;
            if ($clauseStart !== null && $clauseStart->isKeyword('COLUMN')) {
                ++$clauseIndex;
            }

            return $this->alterClause($sql, $tokens, 'add', $clauseIndex);
        }
        if ($operation->isKeyword('DROP')) {
            $columnKeyword = $tokens[$operationIndex + 1] ?? null;
            if ($columnKeyword === null || !$columnKeyword->isKeyword('COLUMN')) {
                return null;
            }

            return $this->alterClause($sql, $tokens, 'drop', $operationIndex + 2);
        }
        if (!$operation->isKeyword('RENAME')) {
            return null;
        }

        $clauseIndex = $operationIndex + 1;
        $clauseStart = $tokens[$clauseIndex] ?? null;
        if ($clauseStart === null) {
            return null;
        }
        if ($clauseStart->isKeyword('TO')) {
            return $this->alterClause($sql, $tokens, 'rename_table', $clauseIndex + 1);
        }
        if ($clauseStart->isKeyword('COLUMN')) {
            ++$clauseIndex;
        }

        return $this->alterClause($sql, $tokens, 'rename_column', $clauseIndex);
    }

    /**
     * @param list<SqlToken> $tokens
     * @param 'add'|'drop'|'rename_table'|'rename_column' $kind
     * @return array{kind: 'add'|'drop'|'rename_table'|'rename_column', clause: string}|null
     */
    public function alterClause(string $sql, array $tokens, string $kind, int $startIndex): ?array
    {
        $first = $tokens[$startIndex] ?? null;
        if ($first === null) {
            return null;
        }
        $last = $tokens[count($tokens) - 1];
        $endOffset = $last->kind === SqlTokenKind::Symbol && $last->text === ';'
            ? $last->offset
            : $last->endOffset();

        return [
            'kind' => $kind,
            'clause' => substr($sql, $first->offset, $endOffset - $first->offset),
        ];
    }

    /**
     * Parses supported ALTER TABLE operation names and identifiers.
     */
    public function singleIdentifier(string $sql): ?string
    {
        $stream = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create());
        $tokens = $stream->significantTokens();
        $identifier = $stream->identifierAt();

        return $identifier !== null && $identifier['next'] === count($tokens)
            ? $identifier['name']
            : null;
    }

    /**
     * @return array{string, string}|null
     */
    public function renamedIdentifiers(string $sql): ?array
    {
        $stream = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create());
        $tokens = $stream->significantTokens();
        $old = $stream->identifierAt();
        if ($old === null) {
            return null;
        }
        $separator = $tokens[$old['next']] ?? null;
        if ($separator === null || !$separator->isKeyword('TO')) {
            return null;
        }
        $new = $stream->identifierAt($old['next'] + 1);
        if ($new === null || $new['next'] !== count($tokens)) {
            return null;
        }

        return [$old['name'], $new['name']];
    }
}
