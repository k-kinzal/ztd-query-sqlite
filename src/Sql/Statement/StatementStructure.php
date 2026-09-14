<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Statement;

use ZtdQuery\Sql\SqlTokenStream;

/**
 * Extracts statement boundaries and referenced relations.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class StatementStructure
{
    /**
     * Split a SQL string into individual statements.
     *
     * @return list<string>
     */
    public function splitStatements(string $sql): array
    {
        return SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->splitStatements();
    }

    /**
     * Extract table names referenced in a SELECT statement.
     *
     * @return array<int, string>
     */
    public function extractSelectTables(string $sql): array
    {
        return (new \ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser())->tableNames($sql);
    }

    /**
     * @param non-empty-list<string> $keywords
     */
    public function statementTail(string $sql, array $keywords): string
    {
        foreach (SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens() as $token) {
            if (!$token->isTopLevel()) {
                continue;
            }
            foreach ($keywords as $keyword) {
                if ($token->isKeyword($keyword)) {
                    return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments(substr($sql, $token->offset));
                }
            }
        }

        return (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\LiteralMasker())->stripComments($sql);
    }
}
