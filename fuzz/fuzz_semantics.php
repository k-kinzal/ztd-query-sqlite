<?php

declare(strict_types=1);

use Faker\Factory;
use Fuzz\Semantics\CommandSequence;
use Fuzz\Semantics\SemanticsTarget;
use SqlFaker\Generation\Coverage\GrammarCoverage;
use SqlFaker\SqliteProvider;

$coverage = getenv('SQLFAKER_COVERAGE') === '0' ? null : new GrammarCoverage(__DIR__ . '/coverage/semantics');
$provider = new SqliteProvider(Factory::create(), 'sqlite-3.47.2', $coverage);

/**
 * @var PhpFuzzer\Config $config
 */
$config->setTarget(Closure::fromCallable(new SemanticsTarget(new CommandSequence($provider))));
$config->setMaxLen(128);
$config->setAllowedExceptions([]);
