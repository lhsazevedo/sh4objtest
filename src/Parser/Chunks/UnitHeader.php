<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

use Lhsazevedo\Sh4ObjTest\BinaryReader;

class UnitHeader extends Base
{
    public int $format;

    public int $nSections;

    public int $nExtRefs;

    public int $nExtDefs;

    public string $unitName;

    public string $toolName;

    public string $toolDate;

    /** @var SectionHeader[] */
    public array $sections;

    public function __construct(BinaryReader $reader)
    {
        $this->format = $reader->readUInt8() & 3;
        $this->nSections = $reader->readUInt16();
        $this->nExtRefs = $reader->readUInt16();
        $this->nExtDefs = $reader->readUInt16();
        $this->unitName = $reader->readBytes($reader->readUInt8());
        $this->toolName = $reader->readBytes($reader->readUInt8());
        $this->toolDate = $reader->readBytes(12);

        // TODO
        //linkerName = $reader->readBytes($reader->readUInt8());
        //linkerDate = $reader->readBytes(12);
    }

    public function addSection(SectionHeader $section): void
    {
        $this->sections[] = $section;
    }

    public function findExportedSymbol(string $name): ?ExportSymbol
    {
        foreach ($this->sections as $section) {
            if ($symbol = $section->findExportedSymbol($name)) {
                return $symbol;
            }
        }

        return null;
    }

    public function findExportedAddress(int $address): ?ExportSymbol
    {
        foreach ($this->sections as $section) {
            if ($symbol = $section->findExportedAddress($address)) {
                return $symbol;
            }
        }

        return null;
    }
}
