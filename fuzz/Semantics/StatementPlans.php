<?php

declare(strict_types=1);

namespace Fuzz\Semantics;

use SqlFaker\Generation\Plan\GenerationPlan;
use SqlFaker\Generation\Plan\LexemeConstraint;
use SqlFaker\Generation\Plan\ProductionPattern;
use SqlFaker\Generation\Plan\RulePlan;

/**
 * Declares the fixture's names and expression relationships without global occurrence counters.
 */
final class StatementPlans
{
    /**
     * @return GenerationPlan<true>
     */
    public static function operation(int $operation, int $id, int $value, string $name): GenerationPlan
    {
        $command = match ($operation) {
            1 => self::insert($id, $value, $name),
            2, 5 => self::update($operation, $id, $value, $name),
            3 => RulePlan::any()->allowing(ProductionPattern::containing('DELETE'))
                ->withRule('where_opt_ret', self::where($id)),
            default => self::select($operation, $id, $value),
        };
        $command = $command->withRule('xfullname', self::identifier('users')->allowing(ProductionPattern::exactly('nm')));
        $plan = GenerationPlan::fromRule('cmd')->withRule('cmd', $command)->requiringNonEmpty()->withExpansionBudget(128);
        foreach (['with', 'orconf', 'indexed_opt', 'idlist_opt', 'upsert', 'distinct', 'sclp', 'stl_prefix', 'dbnm', 'on_using', 'groupby_opt', 'having_opt', 'limit_opt', 'sortorder', 'nulls', 'as', 'from', 'where_opt', 'orderby_opt'] as $rule) {
            $plan = $plan->withRule($rule, RulePlan::any()->allowing(ProductionPattern::exactly()));
        }
        return $plan;
    }

    /**
     * VALUES and SELECT share the same ordered expression plans.
     */
    public static function insert(int $id, int $value, string $name): RulePlan
    {
        $expressions = [self::literal('INTEGER', (string) $id), self::literal('STRING', $name), self::literal('INTEGER', (string) $value)];
        $items = array_map(static fn (RulePlan $expression): RulePlan => RulePlan::any()->withRule('expr', $expression), $expressions);
        return RulePlan::any()->allowing(ProductionPattern::containing('insert_cmd', 'select', 'upsert'))
            ->withRule('insert_cmd', RulePlan::any()->allowing(ProductionPattern::exactly('INSERT', 'orconf')))
            ->withRule('select', self::source()->withRule('oneselect', RulePlan::any()->allowing(ProductionPattern::anyOf(
                ProductionPattern::exactly('values'),
                self::selectProduction(),
            ))))
            ->withRule('values', RulePlan::any()->allowing(ProductionPattern::exactly('VALUES', 'LP', 'nexprlist', 'RP')))
            ->withRule('nexprlist', RulePlan::any()->withItems(...$items))
            ->withRule('selcollist', self::projection($expressions));
    }

    /**
     * Assignment and predicate scopes keep their identifiers and values independent.
     */
    public static function update(int $operation, int $id, int $value, string $name): RulePlan
    {
        $expression = $operation === 2
            ? self::binary(self::identifier('score')->allowing(ProductionPattern::exactly('idj')), ProductionPattern::anyOf(
                ProductionPattern::exactly('expr', 'PLUS', 'expr'),
                ProductionPattern::exactly('expr', 'MINUS', 'expr'),
            ), self::literal('INTEGER', (string) $value))
            : self::literal('STRING', $name);
        $assignment = RulePlan::any()->allowing(ProductionPattern::exactly('nm', 'EQ', 'expr'))
            ->withRule('nm', self::identifier($operation === 2 ? 'score' : 'name')->allowing(ProductionPattern::exactly('idj')))
            ->withRule('expr', $expression);
        return RulePlan::any()->allowing(ProductionPattern::containing('UPDATE'))
            ->withRule('setlist', RulePlan::any()->withItems($assignment))
            ->withRule('where_opt_ret', self::where($id));
    }

    /**
     * Builds either the ordered rows or an aggregate over the same fixture.
     */
    public static function select(int $operation, int $id, int $value): RulePlan
    {
        $aggregate = $operation === 4;
        $expression = $aggregate
            ? self::identifier('count')->allowing(ProductionPattern::exactly('idj', 'LP', 'STAR', 'RP'))
            : self::identifier('id')->allowing(ProductionPattern::exactly('idj'));
        $projection = $aggregate
            ? self::projection([$expression])->withRule('as', self::identifier('total')->allowing(ProductionPattern::exactly('AS', 'nm')))
            : RulePlan::any()->allowing(ProductionPattern::exactly('sclp', 'scanpt', 'STAR'));
        $predicate = self::binary(
            self::identifier($aggregate ? 'id' : 'score')->allowing(ProductionPattern::exactly('idj')),
            ProductionPattern::exactly('expr', $aggregate ? 'LE' : 'GE', 'expr'),
            self::literal('INTEGER', (string) ($aggregate ? $id : $value)),
        );
        $query = self::source()->withRule('oneselect', RulePlan::any()->allowing(self::selectProduction()))
            ->withRule('selcollist', $projection)
            ->withRule('from', RulePlan::any()->allowing(ProductionPattern::exactly('FROM', 'seltablist')))
            ->withRule('seltablist', self::identifier('users')->allowing(ProductionPattern::exactly('stl_prefix', 'nm', 'dbnm', 'as', 'on_using')))
            ->withRule('where_opt', RulePlan::any()->allowing(ProductionPattern::exactly('WHERE', 'expr'))->withRule('expr', $predicate));
        if (!$aggregate) {
            $query = $query->withRule('orderby_opt', RulePlan::any()->allowing(ProductionPattern::exactly('ORDER', 'BY', 'sortlist')))
                ->withRule('sortlist', RulePlan::any()->allowing(ProductionPattern::exactly('expr', 'sortorder', 'nulls'))->withRule('expr', $expression));
        }
        return RulePlan::any()->allowing(ProductionPattern::exactly('select'))->withRule('select', $query);
    }

    /**
     * Keeps the predicate tied to the fixture's primary key.
     */
    public static function where(int $id): RulePlan
    {
        return RulePlan::any()->allowing(ProductionPattern::exactly('WHERE', 'expr'))->withRule('expr', self::binary(
            self::identifier('id')->allowing(ProductionPattern::exactly('idj')),
            ProductionPattern::exactly('expr', 'EQ', 'expr'),
            self::literal('INTEGER', (string) $id),
        ));
    }

    /**
     * Specifies operand roles locally, independently of earlier expressions.
     */
    public static function binary(RulePlan $left, ProductionPattern $operator, RulePlan $right): RulePlan
    {
        return RulePlan::any()->allowing($operator)->withChild('expr', 0, $left)->withChild('expr', 1, $right);
    }

    /**
     * Keeps a supplied value within its lexical token class.
     */
    public static function literal(string $token, string $value): RulePlan
    {
        return RulePlan::any()->allowing(ProductionPattern::exactly('term'))
            ->withRule('term', RulePlan::any()->allowing(ProductionPattern::exactly($token))->withLexeme($token, LexemeConstraint::oneOf($value)));
    }

    /**
     * Binds a name inside its scope, regardless of preceding identifiers.
     */
    public static function identifier(string $name): RulePlan
    {
        return RulePlan::any()->withRule('nm', RulePlan::any()->allowing(ProductionPattern::exactly('idj')))
            ->withRule('idj', RulePlan::any()->allowing(ProductionPattern::exactly('ID')))
            ->withLexeme('ID', LexemeConstraint::oneOf($name));
    }

    /**
     * Shares the statement source rule without fixing VALUES versus SELECT.
     */
    public static function source(): RulePlan
    {
        return RulePlan::any()->allowing(ProductionPattern::exactly('selectnowith'))
            ->withRule('selectnowith', RulePlan::any()->allowing(ProductionPattern::exactly('oneselect')));
    }

    /**
     * Selects the ordinary query production, leaving its children to their scopes.
     */
    public static function selectProduction(): ProductionPattern
    {
        return ProductionPattern::exactly('SELECT', 'distinct', 'selcollist', 'from', 'where_opt', 'groupby_opt', 'having_opt', 'orderby_opt', 'limit_opt');
    }

    /**
     * SQLite's projection list recurses through its optional prefix rule.
     * @param non-empty-list<RulePlan> $expressions
     */
    public static function projection(array $expressions): RulePlan
    {
        $prefix = RulePlan::any()->allowing(ProductionPattern::exactly());
        $projection = RulePlan::any();
        foreach ($expressions as $expression) {
            $projection = RulePlan::any()->allowing(ProductionPattern::exactly('sclp', 'scanpt', 'expr', 'scanpt', 'as'))
                ->withRule('expr', $expression)->withRule('sclp', $prefix);
            $prefix = RulePlan::any()->allowing(ProductionPattern::exactly('selcollist', 'COMMA'))->withRule('selcollist', $projection);
        }
        return $projection;
    }
}
