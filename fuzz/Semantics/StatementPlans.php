<?php

declare(strict_types=1);

namespace Fuzz\Semantics;

use SqlFaker\Generation\Plan\GenerationPlan;
use SqlFaker\Generation\Plan\ProductionPattern;

/**
 * Constrains SQLite grammar productions to the schema and operations used by the native oracle.
 */
final class StatementPlans
{
    /**
     * Selects a schema-valid operation while leaving token spelling to SQLFaker.
     *
     * @return GenerationPlan<true>
     */
    public static function operation(int $operation, int $id, int $value, string $name): GenerationPlan
    {
        $select = ['SELECT', 'distinct', 'selcollist', 'from', 'where_opt', 'groupby_opt', 'having_opt', 'orderby_opt', 'limit_opt'];
        $patterns = match ($operation) {
            1 => [
                'cmd' => [['with', 'insert_cmd', 'INTO', 'xfullname', 'idlist_opt', 'select', 'upsert']],
                'insert_cmd' => [['INSERT', 'orconf']], 'select' => [['selectnowith']],
                'selectnowith' => [['oneselect']], 'oneselect' => [['values']],
                'values' => [['VALUES', 'LP', 'nexprlist', 'RP']],
                'nexprlist' => [['nexprlist', 'COMMA', 'expr'], ['nexprlist', 'COMMA', 'expr'], ['expr']],
                'expr' => [['term'], ['term'], ['term']], 'term' => [['INTEGER'], ['STRING'], ['INTEGER']],
            ],
            2, 5 => [
                'cmd' => [['with', 'UPDATE', 'orconf', 'xfullname', 'indexed_opt', 'SET', 'setlist', 'from', 'where_opt_ret']],
                'setlist' => [['nm', 'EQ', 'expr']], 'from' => [[]],
                'where_opt_ret' => [['WHERE', 'expr']],
                'expr' => $operation === 2
                    ? [['expr', ($value & 1) === 0 ? 'PLUS' : 'MINUS', 'expr'], ['idj'], ['term'], ['expr', 'EQ', 'expr'], ['idj'], ['term']]
                    : [['term'], ['expr', 'EQ', 'expr'], ['idj'], ['term']],
                'term' => $operation === 2 ? [['INTEGER'], ['INTEGER']] : [['STRING'], ['INTEGER']],
            ],
            3 => [
                'cmd' => [['with', 'DELETE', 'FROM', 'xfullname', 'indexed_opt', 'where_opt_ret']],
                'where_opt_ret' => [['WHERE', 'expr']],
                'expr' => [['expr', 'EQ', 'expr'], ['idj'], ['term']], 'term' => [['INTEGER']],
            ],
            default => [
                'cmd' => [['select']], 'select' => [['selectnowith']], 'selectnowith' => [['oneselect']], 'oneselect' => [$select],
                'selcollist' => $operation === 4 ? [['sclp', 'scanpt', 'expr', 'scanpt', 'as']] : [['sclp', 'scanpt', 'STAR']],
                'as' => $operation === 4 ? [['AS', 'nm'], []] : [[]],
                'from' => [['FROM', 'seltablist']], 'seltablist' => [['stl_prefix', 'nm', 'dbnm', 'as', 'on_using']],
                'where_opt' => [['WHERE', 'expr']],
                'orderby_opt' => $operation === 4 ? [[]] : [['ORDER', 'BY', 'sortlist']],
                'sortlist' => [['expr', 'sortorder', 'nulls']],
                'expr' => $operation === 4
                    ? [['idj', 'LP', 'STAR', 'RP'], ['expr', 'LE', 'expr'], ['idj'], ['term']]
                    : [['expr', 'GE', 'expr'], ['idj'], ['term'], ['idj']],
                'term' => [['INTEGER']],
            ],
        };
        $rules = [];
        foreach ($patterns as $rule => $alternatives) {
            $rules[$rule] = array_map(static fn (array $symbols): ProductionPattern => ProductionPattern::exactly(...$symbols), $alternatives);
        }
        $plan = GenerationPlan::constrained('cmd', $rules)->requiringNonEmpty()->withExpansionBudget(128);
        foreach (['with', 'orconf', 'indexed_opt', 'idlist_opt', 'upsert', 'distinct', 'sclp', 'stl_prefix', 'dbnm', 'on_using', 'groupby_opt', 'having_opt', 'limit_opt', 'sortorder', 'nulls'] as $rule) {
            $plan = $plan->withPatternForEveryOccurrence($rule, ProductionPattern::exactly());
        }
        $lexemes = match ($operation) {
            1 => ['ID' => ['users'], 'INTEGER' => [(string) $id, (string) $value], 'STRING' => [$name]],
            2 => ['ID' => ['users', 'score', 'score', 'id'], 'INTEGER' => [(string) $value, (string) $id]],
            3 => ['ID' => ['users', 'id'], 'INTEGER' => [(string) $id]],
            4 => ['ID' => ['count', 'total', 'users', 'id'], 'INTEGER' => [(string) $id]],
            5 => ['ID' => ['users', 'name', 'id'], 'STRING' => [$name], 'INTEGER' => [(string) $id]],
            default => ['ID' => ['users', 'score', 'id'], 'INTEGER' => [(string) $value]],
        };
        return $plan->withPatternForEveryOccurrence('nm', ProductionPattern::exactly('idj'))
            ->withPatternForEveryOccurrence('idj', ProductionPattern::exactly('ID'))
            ->withLexemes($lexemes);
    }
}
