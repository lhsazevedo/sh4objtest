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
     * Serializes the two hit-sets to plain int arrays so coverage can cross
     * a worker/controller process boundary as JSON.
     *
     * @return array{execute: int[], access: int[]}
     */
    public function toArray(): array
    {
        return [
            'execute' => array_keys($this->executeAddresses),
            'access' => array_keys($this->accessAddresses),
        ];
    }

    /** @param array{execute: int[], access: int[]} $data */
    public static function fromArray(array $data): self
    {
        $tracker = new self();

        foreach ($data['execute'] as $address) {
            $tracker->executeAddresses[$address] = true;
        }

        foreach ($data['access'] as $address) {
            $tracker->accessAddresses[$address] = true;
        }

        return $tracker;
    }

    /**
     * Returns per-section coverage data. Sections are the natural split
     * between code and data, since assembler-built objects emit debug-line
     * records for data directives too, not just instructions.
     *
     * @param array<int,true> $ignoredLines line numbers excluded by coverage tags
     * @return array<int, array{sectionNumber: int, fileNumber: int, covered: int, total: int, uncoveredLines: int[], coveredLines: int[]}>
     */
    public function getReport(ParsedObject $parsedObject, array $ignoredLines = []): array
    {
        $report = [];

        foreach ($parsedObject->unit->debugLines as $line) {
            if ($line->lineNumber === 0 || $line->toAddress <= $line->fromAddress) {
                continue;
            }

            if (!$this->isInScope($parsedObject, $line)) {
                continue;
            }

            if (isset($ignoredLines[$line->lineNumber])) {
                continue;
            }

            $sn = $line->sectionNumber;
            if (!isset($report[$sn])) {
                $report[$sn] = [
                    'sectionNumber' => $sn,
                    'fileNumber' => $line->fileNumber,
                    'covered' => 0,
                    'total' => 0,
                    'uncoveredLines' => [],
                    'coveredLines' => [],
                ];
            }

            $report[$sn]['total']++;

            if ($this->isLineCovered($parsedObject, $line)) {
                $report[$sn]['covered']++;
                $report[$sn]['coveredLines'][] = $line->lineNumber;
            } else {
                $report[$sn]['uncoveredLines'][] = $line->lineNumber;
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

    /**
     * Per-section variable-touch coverage. Compiler-built objects carry no
     * debug-line records for data sections (only for code), so getReport()
     * never sees their data sections at all; this is the fallback signal for
     * those sections, grouping the same touched-byte data getSymbolReport()
     * uses but keyed by section like getReport() so the two can be merged.
     *
     * @return array<int, array{sectionNumber: int, covered: int, total: int, touchedNames: string[], untouchedNames: string[]}>
     */
    public function getSymbolCoverageBySection(ParsedObject $object): array
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

            $sn = $symbol->section;
            if (!isset($report[$sn])) {
                $report[$sn] = [
                    'sectionNumber' => $sn,
                    'covered' => 0,
                    'total' => 0,
                    'touchedNames' => [],
                    'untouchedNames' => [],
                ];
            }

            $from = $base + $symbol->address;
            $to   = $from + ($symbol->dataLength ?: 1);

            $report[$sn]['total']++;

            if ($this->rangeTouched($this->accessAddresses, $from, $to)) {
                $report[$sn]['covered']++;
                $report[$sn]['touchedNames'][] = $symbol->name;
            } else {
                $report[$sn]['untouchedNames'][] = $symbol->name;
            }
        }

        return $report;
    }
}
