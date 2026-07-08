<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

interface EventListener
{
    public function onSuiteStarted(int $totalFiles): void;

    public function onFileStarted(string $testFile, string $objectFile): void;

    public function onTestPassed(string $name, string $message): void;

    public function onTestFailed(string $name, string $message): void;

    public function onMessage(string $message): void;

    public function onDisasm(string $message): void;

    /** A file couldn't be run at all (parse error, etc), as opposed to a test within it failing. */
    public function onFileError(string $testFile, string $objectFile, string $message): void;

    public function onFileFinished(string $testFile, string $objectFile, bool $success): void;

    public function onSuiteFinished(SuiteResult $result): void;
}
