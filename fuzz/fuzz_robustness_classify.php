<?php

declare(strict_types=1);

use Faker\Factory;
use Fuzz\Robustness\Target\ClassifyTarget;
use SqlFaker\SqliteProvider;

$faker = Factory::create();
$provider = new SqliteProvider($faker);
$target = new ClassifyTarget($faker, $provider);

/** @var \PhpFuzzer\Config $config */
$config->setTarget(\Closure::fromCallable($target));
