<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\View;

use ZtdQuery\Schema\ViewDefinition;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Extracts a view name and SELECT body from a CREATE VIEW statement.
 */
final class SqliteViewDefinitionParser
{
    /**
     * Returns from query.
     */
    public function fromQuery(string $query): ViewDefinition
    {
        $query = rtrim(trim($query), ';');

        return new ViewDefinition($query, (new \ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser())->tableNames($query));
    }

    /**
     * Returns from create statement.
     */
    public function fromCreateStatement(string $sql): ?ViewDefinition
    {
        foreach (SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens() as $token) {
            if (!$token->isTopLevel() || !$token->isKeyword('AS')) {
                continue;
            }
            $query = substr($sql, $token->endOffset());
            if (trim($query) !== '') {
                return $this->fromQuery($query);
            }
        }

        return null;
    }
}
