<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Parser\Stype;
use Lhsazevedo\Sh4ObjTest\Parser\DebugLine;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;

class CoverageTracker
{
    /**
     * Addresses used as a set: address => true.
     * @var array<int,true>
     */
    private array $executeAddresses = [];

    /**
     * Addresses used as a set: address => true.
     * @var array<int,true>
     */
    private array $accessAddresses = [];

    public function logRead(int $address, int $size): void
    {
        $this->logAccess($address, $size);
    }

    public function logWrite(int $address, int $size): void
    {
        $this->logAccess($address, $size);
    }

    private function logAccess(int $address, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            $this->accessAddresses[$address + $i] = true;
        }
    }

    public function logExecute(int $address, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            $this->executeAddresses[$address + $i] = true;
        }
    }

    public function merge(CoverageTracker $other): void
    {
        $this->executeAddresses += $other->executeAddresses;
        $this->accessAddresses += $other->accessAddresses;
    }

    /**
     * Returns per-file coverage data.
     *
     * @return array<int, array{covered: int, total: int, uncoveredLines: int[], coveredLines: int[]}>
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
                $report[$fn] = ['covered' => 0, 'total' => 0, 'uncoveredLines' => [], 'coveredLines' => []];
            }

            $report[$fn]['total']++;

            if ($this->isLineCovered($parsedObject, $line)) {
                $report[$fn]['covered']++;
                $report[$fn]['coveredLines'][] = $line->lineNumber;
            } else {
                $report[$fn]['uncoveredLines'][] = $line->lineNumber;
            }
        }

        return $report;
    }

    private function isInScope(ParsedObject $object, DebugLine $line): bool
    {
        if ($object->unit->sourceFiles === []) {
            return true;
        }

        return $line->fileNumber === 0;
    }

    private function isLineCovered(ParsedObject $object, DebugLine $line): bool
    {
        $base = $this->sectionBase($object, $line->sectionNumber);
        if ($base === null) {
            return false;
        }

        $from = $base + $line->fromAddress;
        $to   = $base + $line->toAddress;

        // A line counts as covered if it was executed, or if any byte in its
        // range was read/written (literal pool accesses).
        return $this->rangeTouched($this->executeAddresses, $from, $to)
            || $this->rangeTouched($this->accessAddresses, $from, $to);
    }

    /** @param array<int,true> $set */
    private function rangeTouched(array $set, int $from, int $to): bool
    {
        for ($addr = $from; $addr < $to; $addr++) {
            if (isset($set[$addr])) {
                return true;
            }
        }

        return false;
    }

    /** Runtime base address of a section, or null if unknown. */
    private function sectionBase(ParsedObject $object, int $sectionNumber): ?int
    {
        $section = $object->unit->sections[$sectionNumber] ?? null;
        if ($section === null) {
            return null;
        }

        return $section->linkedAddress ?? $section->address;
    }

    /**
     * Per static-variable access report.
     *
     * A symbol counts as touched if any byte
     * in range was read or written.
     *
     * @return array{name: string, touched: bool}[]
     */
    public function getSymbolReport(ParsedObject $object): array
    {
        $report = [];

        foreach ($object->unit->debugSymbols as $symbol) {
            if ($symbol->type !== Stype::Var) {
                continue;
            }

            if ($symbol->section === null || $symbol->address === null) {
                continue;
            }

            if (!$this->isSymbolInScope($object, $symbol)) {
                continue;
            }

            $base = $this->sectionBase($object, $symbol->section);
            if ($base === null) {
                continue;
            }

            $from = $base + $symbol->address;
            $to   = $from + ($symbol->dataLength ?: 1);

            $report[] = [
                'name' => $symbol->name,
                'touched' => $this->rangeTouched($this->accessAddresses, $from, $to),
            ];
        }

        return $report;
    }

    private function isSymbolInScope(ParsedObject $object, DebugSymbol $symbol): bool
    {
        if ($object->unit->sourceFiles === []) {
            return true;
        }

        return $symbol->fileNumber === 0;
    }
}
