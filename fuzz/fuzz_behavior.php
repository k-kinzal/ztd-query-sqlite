<?php

/**
 * Compare grammar-generated SQL with native Sqlite execution and check ZTD isolation.
 *
 * Usage: vendor/bin/php-fuzzer fuzz fuzz/fuzz_behavior.php /path/to/corpus/ --timeout=60
 * Copy sql-faker/seeds/sqlite/sqlite-3.47.2/* into that corpus to replay grammar seeds.
 * Default mode uses the exact sql-faker byte decoder and unconstrained statement root.
 * ZTD_FUZZ_FIXTURES=1 constrains DML table/column roles through Plan for populated fixtures;
 * use a separate corpus for this mode. SQLFAKER_COVERAGE=0 disables coverage recording.
 * Native errors are compared with ZTD rejections, never discarded by an allowlist.
 */

declare(strict_types=1);

use Faker\Factory;
use Fuzz\Target\BehaviorTarget;
use SqlFaker\Generation\Choice\BytePlanCompiler;
use SqlFaker\Generation\Coverage\GrammarCoverage;
use SqlFaker\Generation\Plan\GenerationPlan;
use SqlFaker\Generation\Plan\LexemeConstraint;
use SqlFaker\Generation\Plan\ProductionPattern;
use SqlFaker\Generation\Plan\RulePlan;
use SqlFaker\Sqlite\SqliteProvider;

$scratch = sys_get_temp_dir() . '/ztd-sqlite-fuzz-' . getmypid();
if (!is_dir($scratch) && !mkdir($scratch, 0777, true)) {
    fwrite(STDERR, "Cannot enter the SQLite fuzz scratch directory.\n");
    exit(2);
}
$target = new BehaviorTarget($scratch);

$fixtures = getenv('ZTD_FUZZ_FIXTURES') === '1';
$coverage = getenv('SQLFAKER_COVERAGE') === '0' ? null : new GrammarCoverage(__DIR__ . '/coverage/' . ($fixtures ? 'fixtures' : 'behavior'));
$provider = new SqliteProvider(Factory::create(), 'sqlite-3.47.2', $coverage);
$planner = $provider->planner();
$constraints = GenerationPlan::fromRule('cmd')->requiringNonEmpty();
if ($fixtures) {
    $table = RulePlan::any()->allowing(ProductionPattern::exactly('nm'))
        ->withLexeme('ID', LexemeConstraint::oneOf('items'));
    $constraints = $constraints
        ->withRule('cmd', RulePlan::any()->allowing(ProductionPattern::anyOf(
            ProductionPattern::exactly('select'),
            ProductionPattern::containing('DELETE'),
            ProductionPattern::containing('UPDATE'),
            ProductionPattern::containing('insert_cmd'),
        )))
        ->withRule('nm', RulePlan::any()->allowing(ProductionPattern::exactly('idj')))
        ->withRule('idj', RulePlan::any()->allowing(ProductionPattern::exactly('ID')))
        ->withRule('fullname', $table)->withRule('xfullname', $table)
        ->withRule('seltablist', RulePlan::any()->withChild(
            'nm',
            0,
            RulePlan::any()->withLexeme('ID', LexemeConstraint::oneOf('items'))
        ))
        ->withRule('expr', RulePlan::any()->withChild(
            'idj',
            0,
            RulePlan::any()->withLexeme('ID', LexemeConstraint::oneOf('id', 'value', 'label'))
        ));
    $constraints = $constraints->withExpansionBudget(256);
}

/**
 * @var PhpFuzzer\Config $config
 */
$config->setAllowedExceptions([]);
$config->setMaxLen(80004);
$config->setTarget(static function (string $input) use ($provider, $planner, $constraints, $target): void {
    $plan = (new BytePlanCompiler())->compile($input, $planner, $constraints);
    $target->verify($provider->generate($plan), $input);
});
