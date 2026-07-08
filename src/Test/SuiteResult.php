<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

readonly class SuiteResult
{
    /**
     * @param array<string, ObjectResult> $objectResults keyed by object path
     * @param array{testFile: string, objectFile: string, success: bool, tests: array{name: string, status: string, message: string}[], error?: string}[] $files
     */
    public function __construct(
        public bool $success,
        public array $objectResults,
        public array $files,
    ) {
    }
}
