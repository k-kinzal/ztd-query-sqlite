<?php

declare(strict_types=1);

use Faker\Factory;
use Fuzz\Robustness\Target\RobustnessTarget;
use SqlFaker\SqliteProvider;

$faker = Factory::create();
$provider = new SqliteProvider($faker);
$target = new RobustnessTarget($faker, $provider);

/** @var \PhpFuzzer\Config $config */
$config->setTarget(\Closure::fromCallable($target));
