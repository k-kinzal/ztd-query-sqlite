<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Dml\Update;

use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Extracts UPDATE aliases and top-level filtering clauses.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class UpdateClauseParser
{
    /**
     * Extracts UPDATE aliases and top-level filtering clauses.
     */
    public function extractUpdateAlias(string $sql): ?string
    {
        $tokens = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens();
        foreach ($tokens as $index => $token) {
            if (!$token->isTopLevel() || !$token->isKeyword('UPDATE')) {
                continue;
            }

            $index++;
            if (($tokens[$index] ?? null)?->isKeyword('OR') === true) {
                $index += 2;
            }
            $index = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->identifierEndIndex($tokens, $index);
            if (($tokens[$index] ?? null)?->kind === SqlTokenKind::Symbol
                && $tokens[$index]->text === '.'
            ) {
                $index = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->identifierEndIndex($tokens, $index + 1);
            }
            if (($tokens[$index] ?? null)?->isKeyword('AS') === true) {
                $index++;
            }

            $alias = $tokens[$index] ?? null;
            $set = $tokens[$index + 1] ?? null;
            if ($alias === null
                || $set === null
                || !$set->isKeyword('SET')
                || !in_array($alias->kind, [SqlTokenKind::Word, SqlTokenKind::QuotedIdentifier], true)
            ) {
                return null;
            }

            return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier($alias->text);
        }

        return null;
    }

    /**
     * Extracts UPDATE aliases and top-level filtering clauses.
     */
    public function extractUpdateFromClause(string $sql): ?string
    {
        return SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClause(
            ['FROM'],
            [['WHERE'], ['ORDER', 'BY'], ['LIMIT'], ['RETURNING']],
        );
    }

    /**
     * Extract WHERE clause from a DML statement.
     */
    public function extractWhereClause(string $sql): ?string
    {
        return SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClause(
            ['WHERE'],
            [['ORDER', 'BY'], ['LIMIT'], ['GROUP', 'BY'], ['HAVING'], ['RETURNING']],
        );
    }

    /**
     * Extract ORDER BY clause from a statement.
     */
    public function extractOrderByClause(string $sql): ?string
    {
        return SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClause(
            ['ORDER', 'BY'],
            [['LIMIT'], ['RETURNING']],
        );
    }

    /**
     * Extract LIMIT clause from a statement.
     */
    public function extractLimitClause(string $sql): ?string
    {
        return SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClause(['LIMIT'], [['RETURNING']]);
    }

    /**
     * Extracts UPDATE aliases and top-level filtering clauses.
     */
    public function extractOnConflictUpdateWhere(string $sql): ?string
    {
        return SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClauseAfter(
            ['DO', 'UPDATE', 'SET'],
            ['WHERE'],
            [['RETURNING']],
        );
    }
}
