<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Schema\ViewDefinitionSet;

final class SqliteViewShadowRenderer
{
    /**
     * @param list<string> $tableNames
     * @return array<string, string>
     */
    public function render(ViewDefinitionSet $views, array $tableNames): array
    {
        $definitions = $views->orderedDefinitions();
        $relationNames = array_merge($tableNames, array_keys($definitions));
        $queries = [];
        $relationParser = new SqliteSelectRelationParser();
        foreach ($definitions as $viewName => $definition) {
            $queries[$viewName] = $relationParser->unqualify($definition->query, $relationNames);
        }

        return $queries;
    }
}
