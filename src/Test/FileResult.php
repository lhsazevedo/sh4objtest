<?php declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

class FileResult
{
    /** @var array{name: string, status: string, message: string}[] */
    private array $tests = [];

    public CoverageTracker $coverage;

    public function __construct()
    {
        $this->coverage = new CoverageTracker();
    }

    public function addRun(RunResult $run): void
    {
        $this->tests[] = ['name' => $run->name, 'status' => 'pass', 'message' => $run->message];
        $this->coverage->merge($run->coverage);
    }

    public function addFailure(string $name, string $message): void
    {
        $this->tests[] = ['name' => $name, 'status' => 'fail', 'message' => $message];
    }

    /**
     * @return array{name: string, status: string, message: string}[]
     */
    public function getTests(): array
    {
        return $this->tests;
    }

    public function isSuccessful(): bool
    {
        foreach ($this->tests as $test) {
            if ($test['status'] === 'fail') {
                return false;
            }
        }

        return true;
    }
}
