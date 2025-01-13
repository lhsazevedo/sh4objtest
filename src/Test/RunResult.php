<?php declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

readonly class RunResult
{
    public function __construct(
        public bool $success,
        public CoverageTracker $coverage,
    )
    {}
}
