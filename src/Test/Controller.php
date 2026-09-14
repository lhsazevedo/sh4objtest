<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

/**
 * Spawns and drives a pool of `worker` subprocesses over stdin/stdout NDJSON,
 * dispatching (testFile, objectFile) work items from a shared queue. Each
 * worker returns structured per-test results (not pre-rendered output),
 * which are replayed as events through the same EventListener a sequential
 * run would use, one file's worth at a time.
 */
class Controller
{
    /** @var array<int, array{proc: resource, stdin: resource, stdout: resource, stderr: resource, busy: bool}> */
    private array $workers = [];

    public function __construct(
        private EventListener $events,
        private string $binPath,
        private int $workerCount,
        private bool $shouldTrackCoverage,
        private bool $failFast,
        /** @var string[] */
        private array $callBlocklist,
    ) {
    }

    public static function detectCoreCount(): int
    {
        $nproc = trim((string) @shell_exec('nproc 2>/dev/null'));
        if ($nproc !== '' && ctype_digit($nproc)) {
            return max(1, (int) $nproc);
        }

        return 1;
    }

    /**
     * @param array{testFile: string, objectFile: string}[] $workItems
     */
    public function run(array $workItems): SuiteResult
    {
        if ($workItems === []) {
            return new SuiteResult(true, [], []);
        }

        $queue = $workItems;
        /** @var array<string, ObjectResult> */
        $objectResults = [];
        $files = [];
        $success = true;
        $aborted = false;

        $this->events->onSuiteStarted(count($workItems));

        $count = max(1, min($this->workerCount, count($queue)));
        for ($i = 0; $i < $count; $i++) {
            $this->workers[$i] = $this->spawnWorker();
        }

        foreach (array_keys($this->workers) as $i) {
            $this->dispatchNext($i, $queue);
        }

        while ($this->anyBusy()) {
            $watched = [];
            foreach ($this->workers as $i => $worker) {
                if ($worker['busy']) {
                    $watched[$i] = $worker['stdout'];
                }
            }

            if ($watched === []) {
                break;
            }

            $this->drainStderr();

            $read = array_values($watched);
            $write = $except = [];
            $ready = @stream_select($read, $write, $except, 1);
            if ($ready === false) {
                break;
            }

            foreach ($watched as $i => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }

                $line = fgets($stream);
                if ($line === false) {
                    // Worker died without sending a result.
                    $this->workers[$i]['busy'] = false;
                    $success = false;
                    continue;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                /** @var array<string, mixed> */
                $message = json_decode($line, true);
                $type = $message['type'] ?? null;

                if ($type !== 'file_result' && $type !== 'error') {
                    continue;
                }

                $testFile = (string) $message['testFile'];
                $objectFile = (string) $message['objectFile'];

                $this->events->onFileStarted($testFile, $objectFile);

                if ($type === 'error') {
                    $errorMessage = (string) $message['message'];
                    $this->events->onFileError($testFile, $objectFile, $errorMessage);
                    $success = false;
                    $files[] = [
                        'testFile' => $testFile,
                        'objectFile' => $objectFile,
                        'success' => false,
                        'tests' => [],
                        'error' => $errorMessage,
                    ];
                    $this->events->onFileFinished($testFile, $objectFile, false);
                } else {
                    $fileSuccess = (bool) $message['success'];
                    if (!$fileSuccess) {
                        $success = false;
                    }

                    $objectResults[$objectFile] ??= new ObjectResult($objectFile);
                    if ($this->shouldTrackCoverage && $message['coverage'] !== null) {
                        $objectResults[$objectFile]->mergeCoverage(CoverageTracker::fromArray($message['coverage']));
                    }

                    /** @var array{name: string, status: string, message: string}[] */
                    $tests = $message['tests'] ?? [];
                    foreach ($tests as $test) {
                        if ($test['status'] === 'pass') {
                            $this->events->onTestPassed($test['name'], $test['message']);
                        } else {
                            $this->events->onTestFailed($test['name'], $test['message']);
                        }
                    }

                    $files[] = [
                        'testFile' => $testFile,
                        'objectFile' => $objectFile,
                        'success' => $fileSuccess,
                        'tests' => $tests,
                    ];
                    $this->events->onFileFinished($testFile, $objectFile, $fileSuccess);
                }

                if ($this->failFast && !$success) {
                    $aborted = true;
                    break 2;
                }

                $this->workers[$i]['busy'] = false;
                $this->dispatchNext($i, $queue);
            }
        }

        $this->drainStderr();
        $this->shutdown($aborted);

        $result = new SuiteResult($success, $objectResults, $files);
        $this->events->onSuiteFinished($result);

        return $result;
    }

    /** @return array{proc: resource, stdin: resource, stdout: resource, stderr: resource, busy: bool} */
    private function spawnWorker(): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        // A phpmicro self-executable embeds the script and has an empty
        // PHP_BINARY, so it's run directly; a plain PHAR needs the interpreter.
        $command = php_sapi_name() === 'micro'
            ? [$this->binPath, 'worker']
            : [\PHP_BINARY, $this->binPath, 'worker'];
        $proc = proc_open($command, $descriptors, $pipes);

        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to spawn worker process');
        }

        // Non-blocking so a worker that fills its stderr pipe buffer can't
        // deadlock the controller, which only stream_selects on stdout.
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'busy' => false];
    }

    /** Forwards worker STDERR (PHP warnings, uncaught fatals) and keeps its pipe drained. */
    private function drainStderr(): void
    {
        foreach ($this->workers as $worker) {
            $chunk = @stream_get_contents($worker['stderr']);
            if ($chunk !== false && $chunk !== '') {
                fwrite(STDERR, $chunk);
            }
        }
    }

    /** @param array{testFile: string, objectFile: string}[] $queue */
    private function dispatchNext(int $i, array &$queue): void
    {
        $item = array_shift($queue);
        if ($item === null) {
            return;
        }

        $this->workers[$i]['busy'] = true;

        fwrite($this->workers[$i]['stdin'], json_encode([
            'type' => 'run',
            'testFile' => $item['testFile'],
            'objectFile' => $item['objectFile'],
            'coverage' => $this->shouldTrackCoverage,
            'failFast' => $this->failFast,
            'callBlocklist' => $this->callBlocklist,
        ]) . "\n");
    }

    private function anyBusy(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker['busy']) {
                return true;
            }
        }

        return false;
    }

    private function shutdown(bool $abort): void
    {
        foreach ($this->workers as $worker) {
            if ($abort) {
                @proc_terminate($worker['proc']);
            } else {
                @fwrite($worker['stdin'], json_encode(['type' => 'shutdown']) . "\n");
            }
        }

        foreach ($this->workers as $worker) {
            @fclose($worker['stdin']);
            @fclose($worker['stdout']);
            @fclose($worker['stderr']);
            proc_close($worker['proc']);
        }
    }
}
