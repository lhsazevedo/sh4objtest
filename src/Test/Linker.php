<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Parser\Stype;
use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\Symbol;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;

/**
 * Resolves a parsed object's relocations against the test's symbol resolutions
 * and produces a LinkedProgram ready to load into simulator memory.
 *
 * Section addresses must already be assigned (see SectionHeader::rellocate),
 * which the caller does while assembling $linkedCode.
 */
class Linker
{
    /**
     * @param TestRelocation[] $testRelocations
     * @param string[] $callBlocklist Regexes for assembler labels that aren't functions
     */
    public function link(ParsedObject $object, string $linkedCode, array $testRelocations, array $callBlocklist = []): LinkedProgram
    {
        $memory = new BinaryMemory(strlen($linkedCode), randomize: false);
        $memory->writeBytes(0, $linkedCode);

        $symbols = new SymbolTable();
        $unresolved = [];
        $entryPoints = [];

        foreach ($object->unit->sections as $section) {
            foreach ($section->internalRelocations as $internal) {
                $targetSection = $object->unit->sections[$internal->sectionIndex];
                $site = $internal->linkedAddress;

                // A null addend (REL) is stored in-place; read it back.
                $addend = $internal->addend ?? $memory->readUInt32($site)->value;

                $memory->writeUInt32($site, U32::of($targetSection->linkedAddress + $addend));
            }
        }

        foreach ($object->unit->sections as $section) {
            foreach ($section->exports as $export) {
                $symbols->addSymbol(new Symbol($export->name, U32::of($export->linkedAddress), callable: true));
                $entryPoints[$export->name] ??= $export->offset;
            }
        }

        foreach ($object->unit->sections as $section) {
            foreach ($section->externalRelocations as $relocation) {
                // The patch below only handles 32-bit fields.
                if ($relocation->fieldWidth !== 4) {
                    throw new \Exception("Unsupported external relocation field width {$relocation->fieldWidth} for $relocation->name", 1);
                }

                $resolution = $this->findResolution($relocation->name, $testRelocations);
                if ($resolution === null) {
                    $unresolved[] = $relocation;
                    continue;
                }

                // The in-place value is the literal pool offset; the resolution
                // gives the symbol address. A relocation can't carry both.
                $offset = $memory->readUInt32($relocation->linkedAddress)->value;
                if ($relocation->addend && $offset) {
                    throw new \Exception("Relocation $relocation->name has both built-in and code offset", 1);
                }

                $resolved = U32::of($resolution->address + $relocation->addend + $offset);
                $memory->writeUInt32($relocation->linkedAddress, $resolved);
                $symbols->addSymbol(new Symbol($relocation->name, $resolved, callable: true));
            }
        }

        foreach ($object->unit->debugSymbols as $debugSymbol) {
            if (!$debugSymbol->isStaticDefinition()) {
                continue;
            }

            $section = $object->unit->sections[$debugSymbol->section] ?? null;
            if ($section === null) {
                continue;
            }

            $linkedName = $object->unit->linkedNameOf($debugSymbol);
            $isCode = $section->contents === SectionHeader::CONTENTS_CODE;

            $symbols->addSymbol(new Symbol(
                $linkedName,
                U32::of($section->linkedAddress + $debugSymbol->address),
                $isCode && $this->isCallable($object->unit, $debugSymbol, $linkedName, $callBlocklist),
            ));

            if ($isCode) {
                $entryPoints[$linkedName] ??= $debugSymbol->address;
            }
        }

        return new LinkedProgram(
            $memory->readBytes(0, strlen($linkedCode)),
            $symbols,
            $unresolved,
            $entryPoints,
        );
    }

    /**
     * @param string[] $callBlocklist
     */
    private function isCallable(UnitHeader $unit, DebugSymbol $symbol, string $linkedName, array $callBlocklist): bool
    {
        if ($symbol->type === Stype::Func || $symbol->type === Stype::Proc) {
            return true;
        }

        if ($symbol->type !== Stype::Label || $unit->isCompiled()) {
            return false;
        }

        foreach ($callBlocklist as $pattern) {
            if (preg_match($pattern, $linkedName) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param TestRelocation[] $testRelocations
     */
    private function findResolution(string $name, array $testRelocations): ?TestRelocation
    {
        foreach ($testRelocations as $resolution) {
            if ($resolution->name === $name) {
                return $resolution;
            }
        }

        return null;
    }
}
