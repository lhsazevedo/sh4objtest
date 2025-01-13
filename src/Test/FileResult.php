<?php declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

class FileResult
{
    /** @var RunResult[] */
    private array $runs;

    public CoverageTracker $coverage;

    public function __construct()
    {
        $this->coverage = new CoverageTracker();
    }

    public function addRun(RunResult $run): void
    {
        $this->runs[] = $run;
    }

    /**
     * @return RunResult[]
     */
    public function getRuns(): array
    {
        return $this->runs;
    }
}

