<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Transaction;

use ZtdQuery\Sql\SqlTokenStream;
use ZtdQuery\Sql\TransactionStatement;
use ZtdQuery\Sql\TransactionStatementParser;

/**
 * Parses SQLite transactions and savepoints into portable operations.
 */
final class SqliteTransactionStatementParser implements TransactionStatementParser
{
    /**
     * Parses the supplied SQL into its supported structured representation.
     */
    public function parse(string $sql): ?TransactionStatement
    {
        $tokens = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens();
        if (($tokens[count($tokens) - 1] ?? null)?->text === ';') {
            array_pop($tokens);
        }
        if ((new TransactionTokens())->matchesAny($tokens, [
            ['BEGIN'], ['BEGIN', 'TRANSACTION'], ['BEGIN', 'DEFERRED'], ['BEGIN', 'DEFERRED', 'TRANSACTION'],
            ['BEGIN', 'IMMEDIATE'], ['BEGIN', 'IMMEDIATE', 'TRANSACTION'],
            ['BEGIN', 'EXCLUSIVE'], ['BEGIN', 'EXCLUSIVE', 'TRANSACTION'],
        ])) {
            return TransactionStatement::begin();
        }
        if ((new TransactionTokens())->matchesAny($tokens, [['COMMIT'], ['COMMIT', 'TRANSACTION'], ['END'], ['END', 'TRANSACTION']])) {
            return TransactionStatement::commit();
        }
        if ((new TransactionTokens())->matchesAny($tokens, [['ROLLBACK'], ['ROLLBACK', 'TRANSACTION']])) {
            return TransactionStatement::rollback();
        }
        $name = (new TransactionTokens())->nameAfter($tokens, [['SAVEPOINT']]);
        if ($name !== null) {
            return TransactionStatement::savepoint($name);
        }
        $name = (new TransactionTokens())->nameAfter($tokens, [['ROLLBACK', 'TO'], ['ROLLBACK', 'TO', 'SAVEPOINT']]);
        if ($name !== null) {
            return TransactionStatement::rollbackTo($name);
        }
        $name = (new TransactionTokens())->nameAfter($tokens, [['RELEASE'], ['RELEASE', 'SAVEPOINT']]);

        return $name !== null ? TransactionStatement::release($name) : null;
    }

}
