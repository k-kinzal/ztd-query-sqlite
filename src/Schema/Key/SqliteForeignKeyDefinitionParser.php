<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\Key;

use ZtdQuery\Schema\Key\ForeignKeyDefinition;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Extracts foreign key declarations and referential actions from SQLite schemas.
 */
final class SqliteForeignKeyDefinitionParser
{
    /**
     * @return array<string, ForeignKeyDefinition>
     */
    public function parseCreateTable(
        string $sql,
    ): array {
        $body = (new ForeignKeyTokens())->tableBody($sql);
        if ($body === null) {
            return [];
        }

        $foreignKeys = [];
        foreach (SqlTokenStream::tokenize($body, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->splitTopLevel() as $entry) {
            $stream = SqlTokenStream::tokenize($entry, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create());
            $first = $stream->identifierAt();
            $firstKeyword = $stream->firstTopLevelKeyword();
            $inlineColumn = $first !== null && !in_array(
                $firstKeyword,
                ['CONSTRAINT', 'FOREIGN', 'PRIMARY', 'UNIQUE', 'CHECK', 'EXCLUDE'],
                true,
            ) ? $first['name'] : null;
            $name = sprintf('foreign_%d', count($foreignKeys));
            $definition = (new ForeignKeyEntryParser())->parseEntry($stream, $name, $inlineColumn);
            if ($definition === null) {
                continue;
            }

            $foreignKeys[$definition['name']] = $definition['foreignKey'];
        }

        return $foreignKeys;
    }

}
