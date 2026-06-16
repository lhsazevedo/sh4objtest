<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Parser\Stype;
use Lhsazevedo\Sh4ObjTest\Parser\DebugLine;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;

class CoverageTracker
{
    /** @var int[] */
    private array $executeAddresses = [];

    /** @var int[] */
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
            $byteAddress = $address + $i;
            if (!in_array($byteAddress, $this->accessAddresses)) {
                $this->accessAddresses[] = $byteAddress;
            }
        }
    }

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

        foreach ($other->accessAddresses as $addr) {
            if (!in_array($addr, $this->accessAddresses)) {
                $this->accessAddresses[] = $addr;
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

        foreach ($this->executeAddresses as $addr) {
            if ($addr >= $from && $addr < $to) {
                return true;
            }
        }

        // A line is also considered covered if any data access
        // touched it. This covers literal pool accesses.
        foreach ($this->accessAddresses as $addr) {
            if ($addr >= $from && $addr < $to) {
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

            $touched = false;
            foreach ($this->accessAddresses as $addr) {
                if ($addr >= $from && $addr < $to) {
                    $touched = true;
                    break;
                }
            }

            $report[] = ['name' => $symbol->name, 'touched' => $touched];
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
