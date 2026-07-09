<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Console;

use Lhsazevedo\Sh4ObjTest\Test\NullEventListener;
use Lhsazevedo\Sh4ObjTest\Test\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal command spawned by Controller. Reads one JSON work-item per line
 * from STDIN, runs it, and writes one JSON result per line to STDOUT. Never
 * invoked directly by users.
 */
#[AsCommand(
    name: 'worker',
    description: 'Internal: run test files dispatched by a parallel suite controller',
    hidden: true,
)]
class WorkerCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Nothing but JSON result lines may reach STDOUT, or the protocol
        // desyncs — route warnings/notices to STDERR instead.
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            fwrite(STDERR, "$errstr in $errfile:$errline\n");
            return true;
        });

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            return Command::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed> */
            $message = json_decode($line, true);

            if (($message['type'] ?? null) === 'shutdown') {
                break;
            }

            if (($message['type'] ?? null) !== 'run') {
                continue;
            }

            $this->runItem(
                (string) $message['testFile'],
                (string) $message['objectFile'],
                (bool) ($message['coverage'] ?? false),
                (bool) ($message['failFast'] ?? false),
            );
        }

        fclose($stdin);

        return Command::SUCCESS;
    }

    private function runItem(string $testFile, string $objectFile, bool $trackCoverage, bool $failFast): void
    {
        $runner = new Runner(
            events: new NullEventListener(),
            shouldOutputDisasm: false,
            failFast: $failFast,
        );

        try {
            $fileResult = $runner->runFile($testFile, $objectFile);

            $this->send([
                'type' => 'file_result',
                'testFile' => $testFile,
                'objectFile' => $objectFile,
                'success' => $fileResult->isSuccessful(),
                'tests' => $fileResult->getTests(),
                'coverage' => $trackCoverage ? $fileResult->coverage->toArray() : null,
            ]);
        } catch (\Throwable $e) {
            $this->send([
                'type' => 'error',
                'testFile' => $testFile,
                'objectFile' => $objectFile,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        fwrite(STDOUT, json_encode($message) . "\n");
        fflush(STDOUT);
    }
}
