#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

$test = require $argv[1];

$reflectionClass = new ReflectionClass($test);

// TODO: Setup and teardown.

foreach ($reflectionClass->getMethods() as $reflectionMethod) {
    if ($reflectionMethod->isPublic() && str_starts_with($reflectionMethod->name, 'test')) {
        echo $reflectionMethod->name . "... ";
        call_user_func([$test, $reflectionMethod->name]);
    }
}

