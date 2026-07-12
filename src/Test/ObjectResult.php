<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;

readonly class ObjectResult
{
    private CoverageTracker $coverage;

    public function __construct(
        public string $objectFile,
    ) {
        $this->coverage = new CoverageTracker();
    }

    public function mergeCoverage(CoverageTracker $coverage): void
    {
        $this->coverage->merge($coverage);
    }

    /**
     * @return array<int, array{sectionNumber: int, fileNumber: int, covered: int, total: int, uncoveredLines: int[], coveredLines: int[]}>
     */
    public function getReport(ParsedObject $object): array
    {
        return $this->coverage->getReport($object);
    }

    /**
     * @return array{name: string, touched: bool}[]
     */
    public function getSymbolReport(ParsedObject $object): array
    {
        return $this->coverage->getSymbolReport($object);
    }

    /**
     * @return array<int, array{sectionNumber: int, covered: int, total: int, touchedNames: string[], untouchedNames: string[]}>
     */
    public function getSymbolCoverageBySection(ParsedObject $object): array
    {
        return $this->coverage->getSymbolCoverageBySection($object);
    }
}
