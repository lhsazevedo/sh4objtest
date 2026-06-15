<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;

class CoverageTracker
{
    /** @var int[] */
    private array $executeAddresses = [];

    public function logRead(int $address, int $size): void {}

    public function logWrite(int $address, int $size): void {}

    public function logExecute(int $address, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            $byteAddress = $address + $i;
            if (!in_array($byteAddress, $this->executeAddresses)) {
                $this->executeAddresses[] = $byteAddress;
            }
        }
    }

    public function merge(CoverageTracker $other): void
    {
        foreach ($other->executeAddresses as $addr) {
            if (!in_array($addr, $this->executeAddresses)) {
                $this->executeAddresses[] = $addr;
            }
        }
    }

    /**
     * @return int[]
     */
    public function getExecuteAddresses(): array
    {
        return $this->executeAddresses;
    }

    public function getCoverage(ParsedObject $object): float
    {
        [$covered, $total] = $this->countLines($object);

        return $total === 0 ? 0.0 : $covered / $total;
    }

    /**
     * Returns per-file coverage data.
     *
     * @return array<int, array{covered: int, total: int, uncoveredLines: int[]}>
     */
    public function getReport(ParsedObject $parsedObject): array
    {
        $report = [];

        foreach ($parsedObject->unit->debugLines as $line) {
            if ($line->lineNumber === 0 || $line->toAddress <= $line->fromAddress) {
                continue;
            }

            if (!$this->isInScope($parsedObject, $line)) {
                continue;
            }

            $fn = $line->fileNumber;
            if (!isset($report[$fn])) {
                $report[$fn] = ['covered' => 0, 'total' => 0, 'uncoveredLines' => []];
            }

            $report[$fn]['total']++;

            if ($this->isLineCovered($parsedObject, $line)) {
                $report[$fn]['covered']++;
            } else {
                $report[$fn]['uncoveredLines'][] = $line->lineNumber;
            }
        }

        return $report;
    }

    /**
     * Whether a debug line counts toward coverage. When the object has a
     * source-file table, scope coverage to the main compiled file (file 0) so
     * #included header lines don't dilute a function's coverage. Objects with
     * no table (empty sourceFiles) keep all lines in scope.
     */
    private function isInScope(ParsedObject $object, \Lhsazevedo\Sh4ObjTest\Parser\DebugLine $line): bool
    {
        if ($object->unit->sourceFiles === []) {
            return true;
        }

        return $line->fileNumber === 0;
    }

    private function isLineCovered(ParsedObject $object, \Lhsazevedo\Sh4ObjTest\Parser\DebugLine $line): bool
    {
        $section = $object->unit->sections[$line->sectionNumber] ?? null;
        if ($section === null) {
            return false;
        }

        $base = $section->linkedAddress ?? $section->address;
        $from = $base + $line->fromAddress;
        $to   = $base + $line->toAddress;

        foreach ($this->executeAddresses as $addr) {
            if ($addr >= $from && $addr < $to) {
                return true;
            }
        }

        return false;
    }

    /** @return array{int, int} [covered, total] */
    private function countLines(ParsedObject $object): array
    {
        $covered = 0;
        $total   = 0;

        foreach ($object->unit->debugLines as $line) {
            if ($line->lineNumber === 0 || $line->toAddress <= $line->fromAddress) {
                continue;
            }

            if (!$this->isInScope($object, $line)) {
                continue;
            }

            $total++;

            if ($this->isLineCovered($object, $line)) {
                $covered++;
            }
        }

        return [$covered, $total];
    }
}
