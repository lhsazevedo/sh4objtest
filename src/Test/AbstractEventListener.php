<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

abstract class AbstractEventListener implements EventListener
{
    public function onSuiteStarted(int $totalFiles): void
    {
    }

    public function onFileStarted(string $testFile, string $objectFile): void
    {
        $this->write("◯ {$testFile} <comment>→ {$objectFile}</comment>");
    }

    public function onTestPassed(string $name, string $message): void
    {
        $this->write("    <fg=bright-green;options=bold>✔</> $name ($message)");
    }

    public function onTestFailed(string $name, string $message): void
    {
        $this->write("    <bg=red> FAILED </> <fg=red>{$name}</>");
        $this->write("      <fg=red>{$message}</>");
    }

    public function onMessage(string $message): void
    {
        $this->write($message);
    }

    public function onDisasm(string $message): void
    {
        $this->write($message);
    }

    public function onFileError(string $testFile, string $objectFile, string $message): void
    {
        $this->write("◯ {$testFile} <comment>→ {$objectFile}</comment>");
        $this->write("    <bg=red> ERROR </> <fg=red>{$message}</>");
    }

    public function onFileFinished(string $testFile, string $objectFile, bool $success): void
    {
    }

    public function onSuiteFinished(SuiteResult $result): void
    {
        $total = count($result->files);
        $failed = count(array_filter($result->files, fn (array $f) => !$f['success']));
        $passed = $total - $failed;

        $this->write('');
        $summary = "{$total} files, <fg=green>{$passed} passed</>";
        if ($failed > 0) {
            $summary .= ", <fg=red>{$failed} failed</>";
        }
        $this->write($summary);
    }

    abstract protected function write(string $line): void;
}
