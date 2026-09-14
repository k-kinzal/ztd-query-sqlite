<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Relation;

/**
 * Locates table references and rewrites schema-qualified shadow relations.
 */
final class SqliteSelectRelationParser
{
    /**
     * @return list<string>
     */
    public function fromClauses(string $sql): array
    {
        $tokens = (new RelationSourceParser())->tokens($sql);
        /**
         * @var array<int, array<int, bool>> $selectScopes
         */
        $selectScopes = [];
        $clauses = [];

        foreach ($tokens as $token) {
            if ($token->isKeyword('SELECT')) {
                $selectScopes[$token->depth][$token->bracketDepth] = true;
                continue;
            }
            if ($token->isKeyword('UNION') || $token->isKeyword('INTERSECT') || $token->isKeyword('EXCEPT')) {
                $selectScopes[$token->depth][$token->bracketDepth] = false;
                continue;
            }
            if (!$token->isKeyword('FROM') || ($selectScopes[$token->depth][$token->bracketDepth] ?? false) !== true) {
                continue;
            }

            $end = (new RelationSourceParser())->findFromEnd($sql, $tokens, $token);
            $clause = trim(substr($sql, $token->endOffset(), $end - $token->endOffset()));
            if ($clause !== '') {
                $clauses[] = $clause;
            }
            $selectScopes[$token->depth][$token->bracketDepth] = false;
        }

        return $clauses;
    }

    /**
     * @return list<string>
     */
    public function tableNames(string $sql): array
    {
        $names = [];
        foreach ($this->references($sql) as $reference) {
            $normalized = strtolower($reference['name']);
            if (!isset($names[$normalized])) {
                $names[$normalized] = $reference['name'];
            }
        }

        return array_values($names);
    }

    /**
     * @return list<array{name: string, start: int, unqualifiedStart: int, end: int}>
     */
    public function references(string $sql): array
    {
        $tokens = (new RelationSourceParser())->tokens($sql);
        /**
         * @var array<int, array<int, bool>> $selectScopes
         */
        $selectScopes = [];
        $references = [];

        foreach ($tokens as $token) {
            if ($token->isKeyword('SELECT')) {
                $selectScopes[$token->depth][$token->bracketDepth] = true;
                continue;
            }
            if (!$token->isKeyword('FROM') || ($selectScopes[$token->depth][$token->bracketDepth] ?? false) !== true) {
                continue;
            }

            $clauseStart = $token->endOffset();
            $clauseEnd = (new RelationSourceParser())->findFromEnd($sql, $tokens, $token);
            $clause = substr($sql, $clauseStart, $clauseEnd - $clauseStart);
            foreach ((new RelationSourceParser())->referencesFromClause($clause) as $reference) {
                $references[] = [
                    'name' => $reference['name'],
                    'start' => $clauseStart + $reference['start'],
                    'unqualifiedStart' => $clauseStart + $reference['unqualifiedStart'],
                    'end' => $clauseStart + $reference['end'],
                ];
            }
            $selectScopes[$token->depth][$token->bracketDepth] = false;
        }

        return $references;
    }

    /**
     * @param list<string> $relationNames
     */
    public function unqualify(string $sql, array $relationNames): string
    {
        $targets = array_map(strtolower(...), $relationNames);
        $removals = [];

        foreach ($this->references($sql) as $reference) {
            if ($reference['start'] === $reference['unqualifiedStart']
                || !in_array(strtolower($reference['name']), $targets, true)
            ) {
                continue;
            }
            $removals[] = ['start' => $reference['start'], 'end' => $reference['unqualifiedStart']];
        }

        usort($removals, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($removals as $removal) {
            $sql = substr_replace($sql, '', $removal['start'], $removal['end'] - $removal['start']);
        }

        return $sql;
    }

}
