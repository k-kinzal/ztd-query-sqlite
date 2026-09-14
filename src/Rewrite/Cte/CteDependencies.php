<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Cte;

/**
 * Selects fixture CTEs transitively referenced by SQL, respecting user declarations.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class CteDependencies
{
    /**
     * @param array<string, string> $tableCtes
     * @return array<string, string>
     */
    public function required(string $sql, array $tableCtes): array
    {
        $declared = array_fill_keys((new CteHeaderParser())->parseHeader($sql)['names'], true);
        $requiredSql = [$sql];
        $requiredCtes = [];
        foreach (array_reverse($tableCtes, true) as $table => $cte) {
            $normalized = strtolower($table);
            if (isset($declared[$normalized])) {
                continue;
            }
            $referenced = false;
            foreach ($requiredSql as $requiredPart) {
                if ((new CteReferences())->referencesIdentifier($requiredPart, $table)) {
                    $referenced = true;
                }
            }
            if (!$referenced) {
                continue;
            }
            $requiredCtes[$table] = $cte;
            $requiredSql[] = $cte;
        }

        return array_reverse($requiredCtes, true);
    }
}
