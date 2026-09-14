<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql;

/**
 * Lightweight SQL parser for SQLite.
 *
 * Uses lexical statement classification and focused extraction for the SQL subset needed by ZTD:
 * SELECT, INSERT, UPDATE, DELETE, CREATE TABLE, DROP TABLE, ALTER TABLE ADD COLUMN.
 *
 * Returns structured representations of parsed statements.
 * @visibility public
 * @example Inspect SQLite statements without executing them
 *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->classifyStatement("SELECT 1") // => 'SELECT'
 */
final class SqliteParser
{
    /**
     * Classify the type of a SQL statement.
     *
     * @return string|null Statement type: 'SELECT', 'INSERT', 'UPDATE', 'DELETE',
     *                     'CREATE_TABLE', 'DROP_TABLE', 'ALTER_TABLE', or null if unsupported.
     *
     * @visibility public
     * @example Classify a statement following a CTE
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $parser->classifyStatement('WITH ids AS (SELECT 1) SELECT * FROM ids') // => 'SELECT'
     *     $parser->classifyStatement('VACUUM') // => null
     */
    public function classifyStatement(string $sql): ?string
    {
        return (new Statement\StatementClassifier())->classifyStatement($sql);
    }

    /**
     * Split a SQL string into individual statements.
     *
     * @return list<string>
     *
     * @visibility public
     * @example Split statements without splitting quoted semicolons
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->splitStatements("SELECT ';'; SELECT 2") // => ["SELECT ';'", 'SELECT 2']
     */
    public function splitStatements(string $sql): array
    {
        return (new Statement\StatementStructure())->splitStatements($sql);
    }

    /**
     * Extract the target table name from a DML statement.
     * @visibility public
     * @example Find the table modified by an INSERT
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractTargetTable('INSERT INTO users (id) VALUES (1)') // => 'users'
     */
    public function extractTargetTable(string $sql): ?string
    {
        return (new Statement\TargetTableParser())->extractTargetTable($sql);
    }

    /**
     * Extract table names referenced in a SELECT statement.
     *
     * @return array<int, string>
     * @visibility public
     * @example List tables read by a query
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractSelectTables('SELECT * FROM users JOIN orders ON users.id = orders.user_id') // => ['users', 'orders']
     */
    public function extractSelectTables(string $sql): array
    {
        return (new Statement\StatementStructure())->extractSelectTables($sql);
    }

    /**
     * Extract columns from an INSERT statement.
     *
     * @return array<int, string>
     * @visibility public
     * @example Read explicit INSERT columns
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractInsertColumns("INSERT INTO users (id, name) VALUES (1, 'Alice')") // => ['id', 'name']
     */
    public function extractInsertColumns(string $sql): array
    {
        return (new Dml\Insert\InsertClauseParser())->extractInsertColumns($sql);
    }

    /**
     * Extract VALUES from an INSERT statement.
     *
     * @return array<int, array<int, string>>
     * @visibility public
     * @example Keep VALUES as SQL expressions
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractInsertValues('INSERT INTO users VALUES (1, 2 + 3)') // => [['1', '2 + 3']]
     */
    public function extractInsertValues(string $sql): array
    {
        return (new Dml\Insert\InsertClauseParser())->extractInsertValues($sql);
    }

    /**
     * Extract SET assignments from an UPDATE statement.
     *
     * @return array<string, string> Column name => value expression.
     * @visibility public
     * @example Read SET expressions by column name
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractUpdateAssignments('UPDATE users SET score = score + 1, name = NULL') // => ['score' => 'score + 1', 'name' => 'NULL']
     */
    public function extractUpdateAssignments(string $sql): array
    {
        return (new Dml\Expression\AssignmentParser())->extractUpdateAssignments($sql);
    }

    /**
     * Returns the target alias preceding an UPDATE SET clause.
     * @visibility public
     * @example Find an UPDATE target alias
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractUpdateAlias('UPDATE users AS u SET score = 1') // => 'u'
     */
    public function extractUpdateAlias(string $sql): ?string
    {
        return (new Dml\Update\UpdateClauseParser())->extractUpdateAlias($sql);
    }

    /**
     * Returns the UPDATE FROM source before filtering clauses.
     * @visibility public
     * @example Read an UPDATE FROM source
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractUpdateFromClause('UPDATE users SET score = s.score FROM scores AS s WHERE users.id = s.id') // => 'scores AS s'
     */
    public function extractUpdateFromClause(string $sql): ?string
    {
        return (new Dml\Update\UpdateClauseParser())->extractUpdateFromClause($sql);
    }

    /**
     * Extract WHERE clause from a DML statement.
     * @visibility public
     * @example Extract the filter without its keyword
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractWhereClause('DELETE FROM users WHERE id = 1') // => 'id = 1'
     */
    public function extractWhereClause(string $sql): ?string
    {
        return (new Dml\Update\UpdateClauseParser())->extractWhereClause($sql);
    }

    /**
     * Extract ORDER BY clause from a statement.
     * @visibility public
     * @example Extract ordering expressions
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractOrderByClause('SELECT * FROM users ORDER BY id DESC LIMIT 1') // => 'id DESC'
     */
    public function extractOrderByClause(string $sql): ?string
    {
        return (new Dml\Update\UpdateClauseParser())->extractOrderByClause($sql);
    }

    /**
     * Extract LIMIT clause from a statement.
     * @visibility public
     * @example Read a row limit
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractLimitClause('SELECT * FROM users LIMIT 5') // => '5'
     */
    public function extractLimitClause(string $sql): ?string
    {
        return (new Dml\Update\UpdateClauseParser())->extractLimitClause($sql);
    }

    /**
     * Check if an INSERT statement has ON CONFLICT clause (upsert).
     * @visibility public
     * @example Recognize a conflict clause
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->hasOnConflict('INSERT INTO users VALUES (1) ON CONFLICT DO NOTHING') // => true
     */
    public function hasOnConflict(string $sql): bool
    {
        return (new Dml\Insert\InsertClauseParser())->hasOnConflict($sql);
    }

    /**
     * Check if the statement is INSERT OR REPLACE / REPLACE INTO.
     * @visibility public
     * @example Recognize replacement writes
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->isReplace('INSERT OR REPLACE INTO users VALUES (1)') // => true
     */
    public function isReplace(string $sql): bool
    {
        return (new Dml\Insert\InsertClauseParser())->isReplace($sql);
    }

    /**
     * Check if the statement is INSERT OR IGNORE / INSERT IGNORE.
     * @visibility public
     * @example Recognize ignored conflicts
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->isInsertIgnore('INSERT OR IGNORE INTO users VALUES (1)') // => true
     */
    public function isInsertIgnore(string $sql): bool
    {
        return (new Dml\Insert\InsertClauseParser())->isInsertIgnore($sql);
    }

    /**
     * Extract ON CONFLICT update columns from an upsert statement.
     *
     * @return array<string, string> Column name => value expression.
     * @visibility public
     * @example Read upsert update expressions
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractOnConflictUpdates('INSERT INTO users VALUES (1, 2) ON CONFLICT(id) DO UPDATE SET score = excluded.score') // => ['score' => 'excluded.score']
     */
    public function extractOnConflictUpdates(string $sql): array
    {
        return (new Dml\Expression\AssignmentParser())->extractOnConflictUpdates($sql);
    }

    /**
     * Returns the predicate belonging to DO UPDATE SET.
     * @visibility public
     * @example Distinguish the upsert update predicate
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractOnConflictUpdateWhere('INSERT INTO users VALUES (1, 2) ON CONFLICT(id) DO UPDATE SET score = excluded.score WHERE score < 5') // => 'score < 5'
     */
    public function extractOnConflictUpdateWhere(string $sql): ?string
    {
        return (new Dml\Update\UpdateClauseParser())->extractOnConflictUpdateWhere($sql);
    }

    /**
     * Check if an INSERT has a SELECT subquery.
     * @visibility public
     * @example Recognize INSERT SELECT
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->hasInsertSelect('INSERT INTO users SELECT * FROM archived_users') // => true
     */
    public function hasInsertSelect(string $sql): bool
    {
        return (new Dml\Insert\InsertClauseParser())->hasInsertSelect($sql);
    }

    /**
     * Extract the SELECT subquery from an INSERT ... SELECT statement.
     * @visibility public
     * @example Read the SELECT supplying inserted rows
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->extractInsertSelect('INSERT INTO users SELECT * FROM archived_users') // => 'SELECT * FROM archived_users'
     */
    public function extractInsertSelect(string $sql): ?string
    {
        return (new Dml\Insert\InsertClauseParser())->extractInsertSelect($sql);
    }

    /**
     * Strip SQL comments from a string.
     * @visibility public
     * @example Remove comments before parsing
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->stripComments("SELECT -- comment\n1") // => "SELECT  \n1"
     */
    public function stripComments(string $sql): string
    {
        return (new Lexing\LiteralMasker())->stripComments($sql);
    }

    /**
     * Replaces single-quoted literal spans with spaces at the same offsets.
     * @visibility public
     * @example Preserve offsets while hiding string contents
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->maskStringLiterals("SELECT 'abc'") // => 'SELECT      '
     */
    public function maskStringLiterals(string $sql): string
    {
        return (new Lexing\LiteralMasker())->maskStringLiterals($sql);
    }

    /**
     * Unquote a SQL identifier (double-quoted or backtick-quoted).
     * @visibility public
     * @example Decode a doubled identifier quote
     *     (new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser())->unquoteIdentifier('"user""name"') // => 'user"name'
     */
    public function unquoteIdentifier(string $identifier): string
    {
        return (new Lexing\IdentifierDecoder())->unquoteIdentifier($identifier);
    }
}
