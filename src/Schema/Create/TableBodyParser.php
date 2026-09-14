<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\Create;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Extracts CREATE TABLE definitions and validates table options.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class TableBodyParser
{
    /**
     * Extracts CREATE TABLE definitions and validates table options.
     */
    public function tableBody(string $sql): ?string
    {
        $tablePrefix = '/^CREATE\s+(?:(?:TEMP|TEMPORARY)\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[(?:[^\]])*\]|[^\s(]+)\s*\(/is';
        if (preg_match($tablePrefix, $sql, $matches) !== 1) {
            return null;
        }

        $openingOffset = strlen($matches[0]) - 1;
        $closing = null;
        foreach (SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens() as $token) {
            if ($token->isTopLevel()
                && $token->kind === SqlTokenKind::Symbol
                && $token->text === ')'
            ) {
                $closing = $token;
                break;
            }
        }
        if ($closing === null) {
            return null;
        }

        $suffix = substr($sql, $closing->endOffset());
        if (!self::hasValidTableOptions($suffix)) {
            return null;
        }

        return substr($sql, $openingOffset + 1, $closing->offset - $openingOffset - 1);
    }

    /**
     * Extracts CREATE TABLE definitions and validates table options.
     */
    public static function hasValidTableOptions(string $suffix): bool
    {
        $tokens = SqlTokenStream::tokenize($suffix, SqliteLexerProfile::create())->significantTokens();
        $last = $tokens[count($tokens) - 1] ?? null;
        if ($last !== null
            && $last->kind === SqlTokenKind::Symbol
            && $last->text === ';'
        ) {
            array_pop($tokens);
        }
        if ($tokens === []) {
            return true;
        }

        $seen = [];
        $index = 0;
        while ($index < count($tokens)) {
            if ($tokens[$index]->isKeyword('STRICT')) {
                $option = 'STRICT';
                $index++;
            } elseif ($tokens[$index]->isKeyword('WITHOUT')
                && ($tokens[$index + 1] ?? null)?->isKeyword('ROWID') === true
            ) {
                $option = 'WITHOUT ROWID';
                $index += 2;
            } else {
                return false;
            }

            if (isset($seen[$option])) {
                return false;
            }
            $seen[$option] = $option;

            if ($index === count($tokens)) {
                return true;
            }
            if ($tokens[$index]->kind !== SqlTokenKind::Symbol
                || $tokens[$index]->text !== ','
            ) {
                return false;
            }
            $index++;
        }

        return false;
    }

    /**
     * Extracts CREATE TABLE definitions and validates table options.
     */
    public static function hasWithoutRowid(string $sql): bool
    {
        $withoutClause = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->topLevelClause(['WITHOUT']);
        if ($withoutClause === null) {
            return false;
        }

        return SqlTokenStream::tokenize($withoutClause, SqliteLexerProfile::create())->firstTopLevelKeyword() === 'ROWID';
    }

    /**
     * Split column/constraint definitions by commas, respecting parentheses.
     *
     * @return array<int, string>
     */
    public function splitColumnDefinitions(string $body): array
    {
        $definitions = [];
        $index = 0;
        $length = strlen($body);
        while ($index < $length) {
            $end = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan())->end($body, $index, false);
            $definition = trim(substr($body, $index, $end - $index));
            if ($end < $length || $definition !== '') {
                $definitions[] = $definition;
            }
            $index = $end + 1;
        }

        return $definitions;
    }
}
