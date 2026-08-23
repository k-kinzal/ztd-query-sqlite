<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;
use ZtdQuery\Sql\TransactionStatement;
use ZtdQuery\Sql\TransactionStatementParser;

final class SqliteTransactionStatementParser implements TransactionStatementParser
{
    public function parse(string $sql): ?TransactionStatement
    {
        $tokens = SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens();
        if (($tokens[count($tokens) - 1] ?? null)?->text === ';') {
            array_pop($tokens);
        }
        if ($this->matchesAny($tokens, [
            ['BEGIN'], ['BEGIN', 'TRANSACTION'], ['BEGIN', 'DEFERRED'], ['BEGIN', 'DEFERRED', 'TRANSACTION'],
            ['BEGIN', 'IMMEDIATE'], ['BEGIN', 'IMMEDIATE', 'TRANSACTION'],
            ['BEGIN', 'EXCLUSIVE'], ['BEGIN', 'EXCLUSIVE', 'TRANSACTION'],
        ])) {
            return TransactionStatement::begin();
        }
        if ($this->matchesAny($tokens, [['COMMIT'], ['COMMIT', 'TRANSACTION'], ['END'], ['END', 'TRANSACTION']])) {
            return TransactionStatement::commit();
        }
        if ($this->matchesAny($tokens, [['ROLLBACK'], ['ROLLBACK', 'TRANSACTION']])) {
            return TransactionStatement::rollback();
        }
        $name = $this->nameAfter($tokens, [['SAVEPOINT']]);
        if ($name !== null) {
            return TransactionStatement::savepoint($name);
        }
        $name = $this->nameAfter($tokens, [['ROLLBACK', 'TO'], ['ROLLBACK', 'TO', 'SAVEPOINT']]);
        if ($name !== null) {
            return TransactionStatement::rollbackTo($name);
        }
        $name = $this->nameAfter($tokens, [['RELEASE'], ['RELEASE', 'SAVEPOINT']]);

        return $name !== null ? TransactionStatement::release($name) : null;
    }

    /**
     * @param list<SqlToken> $tokens
     * @param list<list<string>> $forms
     */
    private function matchesAny(array $tokens, array $forms): bool
    {
        foreach ($forms as $form) {
            if ($this->matches($tokens, $form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<SqlToken> $tokens
     * @param list<list<string>> $prefixes
     */
    private function nameAfter(array $tokens, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix) {
            if (count($tokens) !== count($prefix) + 1 || !$this->matches(array_slice($tokens, 0, -1), $prefix)) {
                continue;
            }
            $name = $tokens[count($prefix)];
            if (!in_array($name->kind, [SqlTokenKind::Word, SqlTokenKind::QuotedIdentifier], true)) {
                return null;
            }

            return $this->unquote($name->text);
        }

        return null;
    }

    /**
     * @param list<SqlToken> $tokens
     * @param list<string> $keywords
     */
    private function matches(array $tokens, array $keywords): bool
    {
        if (count($tokens) !== count($keywords)) {
            return false;
        }
        foreach ($keywords as $index => $keyword) {
            if (!$tokens[$index]->isKeyword($keyword)) {
                return false;
            }
        }

        return true;
    }

    private function unquote(string $identifier): ?string
    {
        $first = $identifier[0] ?? '';
        if (!in_array($first, ['"', '`'], true)) {
            return $identifier;
        }
        if (($identifier[strlen($identifier) - 1] ?? '') !== $first) {
            return null;
        }

        return str_replace($first . $first, $first, substr($identifier, 1, -1));
    }
}
