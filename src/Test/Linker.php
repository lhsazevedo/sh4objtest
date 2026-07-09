<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

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
     */
    public function link(ParsedObject $object, string $linkedCode, array $testRelocations): LinkedProgram
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
                $symbols->addSymbol(new Symbol($relocation->name, $resolved));
            }
        }

        foreach ($object->unit->sections as $section) {
            foreach ($section->exports as $export) {
                $symbols->addSymbol(new Symbol($export->name, U32::of($export->linkedAddress)));
                $entryPoints[$export->name] ??= $export->offset;
            }
        }

        // Static (internal-linkage) functions have no Exports entry, but the
        // compiler still emits their name via debug info when built with -debug.
        // Debug symbol names are bare source identifiers; the linker name (as
        // used by Exports/relocations, and thus by test cases) prepends "_".
        foreach ($object->unit->debugSymbols as $debugSymbol) {
            if ($debugSymbol->type !== Stype::Func && $debugSymbol->type !== Stype::Proc) {
                continue;
            }

            // Static symbols lack an external name.
            $linkedName = $debugSymbol->externalName ?? ('_' . $debugSymbol->name);

            if (isset($entryPoints[$linkedName])
                || $debugSymbol->section === null
                || $debugSymbol->address === null) {
                continue;
            }

            $section = $object->unit->sections[$debugSymbol->section] ?? null;
            if ($section === null) {
                continue;
            }

            $entryPoints[$linkedName] = $debugSymbol->address;
            $symbols->addSymbol(new Symbol(
                $linkedName,
                U32::of($section->linkedAddress + $debugSymbol->address),
            ));
        }

        return new LinkedProgram(
            $memory->readBytes(0, strlen($linkedCode)),
            $symbols,
            $unresolved,
            $entryPoints,
        );
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
