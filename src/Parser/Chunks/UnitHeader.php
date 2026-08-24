<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

use Lhsazevedo\Sh4ObjTest\BinaryReader;
use Lhsazevedo\Sh4ObjTest\Parser\DebugLine;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;

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

    /** @var DebugLine[] */
    public array $debugLines = [];

    /** @var DebugSymbol[] */
    public array $debugSymbols = [];

    /**
     * Source/include file paths indexed by debug file number. Index 0 is the
     * main compiled file; the rest are #included headers.
     *
     * @var string[]
     */
    public array $sourceFiles = [];

    public function __construct(BinaryReader $reader)
    {
        $this->format = $reader->readUInt8() & 3;
        $this->nSections = $reader->readUInt16BE();
        $this->nExtRefs = $reader->readUInt16BE();
        $this->nExtDefs = $reader->readUInt16BE();
        $this->unitName = $reader->readBytes($reader->readUInt8());
        $this->toolName = $reader->readBytes($reader->readUInt8());
        $this->toolDate = $reader->readBytes(12);

        // Assembler ("A_SH") objects carry a single trailing zero byte here
        // that compiler objects lack. Purpose unknown; consume it and warn if
        // it is ever longer than one byte or non-zero.
        $trailing = $reader->eatRest();
        if (strlen($trailing) > 1 || ltrim($trailing, "\x00") !== '') {
            printf("WARN: Unexpected UnitHeader trailing bytes: %s\n", bin2hex($trailing));
        }
    }

    public function addSection(SectionHeader $section): void
    {
        $this->sections[] = $section;
    }

    public function addDebugLine(DebugLine $line): void
    {
        $this->debugLines[] = $line;
    }

    public function addDebugSymbol(DebugSymbol $symbol): void
    {
        $this->debugSymbols[] = $symbol;
    }

    public function addSourceFile(string $path): void
    {
        $this->sourceFiles[] = $path;
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

    public function findDebugSymbolAddress(string $linkedName): ?int
    {
        foreach ($this->debugSymbols as $debugSymbol) {
            if (!$debugSymbol->isStaticDefinition() || $debugSymbol->linkedName() !== $linkedName) {
                continue;
            }

            $section = $this->sections[$debugSymbol->section] ?? null;
            if ($section === null) {
                continue;
            }

            return $section->linkedAddress + $debugSymbol->address;
        }

        return null;
    }
}
