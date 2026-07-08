<?php declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

readonly class RunResult
{
    public function __construct(
        public string $name,
        public string $message,
        public CoverageTracker $coverage,
    )
    {}
}
