<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Default suite listener: silent on passing tests/files, prints a file's
 * header lazily only when one of its tests fails, and tracks a single
 * overwriting progress line (TTY only) instead of one line per file. Use
 * -v for the old print-everything behavior (ConsoleEventListener).
 */
class CompactEventListener implements EventListener
{
    private int $total = 0;
    private int $done = 0;
    private int $failed = 0;

    private string $pendingTestFile = '';
    private string $pendingObjectFile = '';
    private bool $pendingHeaderPrinted = false;

    public function __construct(private OutputInterface $output)
    {
    }

    public function onSuiteStarted(int $totalFiles): void
    {
        $this->total = $totalFiles;
        $this->redraw();
    }

    public function onFileStarted(string $testFile, string $objectFile): void
    {
        $this->pendingTestFile = $testFile;
        $this->pendingObjectFile = $objectFile;
        $this->pendingHeaderPrinted = false;
    }

    public function onTestPassed(string $name, string $message): void
    {
    }

    public function onTestFailed(string $name, string $message): void
    {
        $this->ensureHeaderPrinted();
        $this->clearProgress();
        $this->output->writeln("    <bg=red> FAILED </> <fg=red>{$name}</>");
        $this->output->writeln("      <fg=red>{$message}</>");
        $this->redraw();
    }

    public function onMessage(string $message): void
    {
    }

    public function onDisasm(string $message): void
    {
    }

    public function onFileError(string $testFile, string $objectFile, string $message): void
    {
        $this->pendingTestFile = $testFile;
        $this->pendingObjectFile = $objectFile;
        $this->ensureHeaderPrinted();
        $this->clearProgress();
        $this->output->writeln("    <bg=red> ERROR </> <fg=red>{$message}</>");
        $this->redraw();
    }

    public function onFileFinished(string $testFile, string $objectFile, bool $success): void
    {
        $this->done++;
        if (!$success) {
            $this->failed++;
        }
        $this->redraw();
    }

    public function onSuiteFinished(SuiteResult $result): void
    {
        $this->clearProgress();

        $total = count($result->files);
        $failed = array_filter($result->files, fn (array $f) => !$f['success']);
        $passed = $total - count($failed);

        $this->output->writeln('');
        $summary = "{$total} files, <fg=green>{$passed} passed</>";
        if ($failed !== []) {
            $summary .= ', <fg=red>' . count($failed) . ' failed</>';
        }
        $this->output->writeln($summary);

        if ($failed !== []) {
            $this->output->writeln('');
            $this->output->writeln('Failed files:');
            foreach ($failed as $f) {
                $this->output->writeln("  <fg=red>✗</> {$f['testFile']} <comment>→ {$f['objectFile']}</comment>");

                if (isset($f['error'])) {
                    $this->output->writeln("      <fg=red>{$f['error']}</>");
                    continue;
                }

                foreach ($f['tests'] as $test) {
                    if ($test['status'] === 'fail') {
                        $this->output->writeln("      <fg=red>{$test['name']}</>: {$test['message']}");
                    }
                }
            }
        }
    }

    private function ensureHeaderPrinted(): void
    {
        if ($this->pendingHeaderPrinted) {
            return;
        }

        $this->clearProgress();
        $this->output->writeln("◯ {$this->pendingTestFile} <comment>→ {$this->pendingObjectFile}</comment>");
        $this->pendingHeaderPrinted = true;
    }

    private function redraw(): void
    {
        if (!$this->output->isDecorated()) {
            return;
        }

        $line = $this->total > 0
            ? sprintf('  %3d%% %d/%d files', intdiv($this->done * 100, $this->total), $this->done, $this->total)
            : "  {$this->done} files";

        if ($this->failed > 0) {
            $line .= " <fg=red>({$this->failed} failed)</>";
        }

        $this->output->write("\r\033[K{$line}", false);
    }

    private function clearProgress(): void
    {
        if ($this->output->isDecorated()) {
            $this->output->write("\r\033[K", false);
        }
    }
}
