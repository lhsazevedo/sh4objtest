#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once "./vendor/autoload.php";

$test = require $argv[1];
$test->testSimpleTest();
