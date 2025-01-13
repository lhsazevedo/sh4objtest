<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use ParseError;

class CoverageTracker
{
    /** @var int[] */
    private array $readAddresses = [];

    /** @var int[] */
    private array $writeAddresses = [];

    /** @var int[] */
    private array $executeAddresses = [];

    public function logRead(int $address, int $size): void
    {
        for ($i = 0; $i < $size; $i++) { 
            $byteAddress = $address + $i;
            if (in_array($byteAddress, $this->readAddresses)) {
                continue;
            }

            $this->readAddresses[] = $address + $i;
        }
    }

    public function logWrite(int $address, int $size): void
    {
        for ($i = 0; $i < $size; $i++) { 
            $byteAddress = $address + $i;
            if (in_array($byteAddress, $this->writeAddresses)) {
                continue;
            }

            $this->writeAddresses[] = $address + $i;
        }
    }

    public function logExecute(int $address, int $size): void
    {
        for ($i = 0; $i < $size; $i++) { 
            $byteAddress = $address + $i;
            if (in_array($byteAddress, $this->executeAddresses)) {
                continue;
            }

            $this->executeAddresses[] = $address + $i;
        }
    }

    public function merge(CoverageTracker $coverageTracker): void
    {
        $this->readAddresses = array_merge($this->readAddresses, $coverageTracker->getReadAddresses());
        $this->writeAddresses = array_merge($this->writeAddresses, $coverageTracker->getWriteAddresses());
        $this->executeAddresses = array_merge($this->executeAddresses, $coverageTracker->getExecuteAddresses());
    }

    /**
     * @return int[]
     */
    public function getReadAddresses(): array
    {
        return $this->readAddresses;
    }

    /**
     * @return int[]
     */
    public function getWriteAddresses(): array
    {
        return $this->writeAddresses;
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
        $linkedReadAddresses = [];
        $linkedWriteAddresses = [];
        $linkedExecuteAddresses = [];

        $totalBytes = 0;
        foreach ($object->unit->sections as $section) {
            for ($i = 0; $i < $section->length; $i++) { 
                $totalBytes++;
                $currentAddress = $section->address + $i;
                if (in_array($currentAddress, $this->readAddresses)) {
                    $linkedReadAddresses[] = $currentAddress;
                }
                if (in_array($currentAddress, $this->writeAddresses)) {
                    $linkedWriteAddresses[] = $currentAddress;
                }
                if (in_array($currentAddress, $this->executeAddresses)) {
                    $linkedExecuteAddresses[] = $currentAddress;
                }
            }
        }

        $totalAccessedAddresses = count(array_unique(
            array_merge($linkedReadAddresses, $linkedWriteAddresses, $linkedExecuteAddresses)
        ));
        $totalReadAddresses = count(array_unique($this->readAddresses));
        $totalWriteAddresses = count(array_unique($this->writeAddresses));
        $totalExecuteAddresses = count(array_unique($this->executeAddresses));

        return $totalAccessedAddresses / $totalBytes;
    }

    /**
     * @return array<int, array{0: bool, 1: bool, 2: bool}>
     */
    public function getReport(ParsedObject $parsedObject): array
    {
        /** @var array<int, array{0: bool, 1: bool, 2: bool}> */
        $report = [];

        foreach ($parsedObject->unit->sections as $section) {
            for ($i = 0; $i < $section->length; $i++) { 
                $currentAddress = $section->address + $i;
                $read = in_array($currentAddress, $this->readAddresses);
                $write = in_array($currentAddress, $this->writeAddresses);
                $execute = in_array($currentAddress, $this->executeAddresses);

                $report[$currentAddress] = [$read, $write, $execute];
            }
        }

        return $report;
    }
}
