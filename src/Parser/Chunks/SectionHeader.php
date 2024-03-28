<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

use Lhsazevedo\Sh4ObjTest\BinaryReader;
use Lhsazevedo\Sh4ObjTest\Parser\LocalRelocationLong;
use Lhsazevedo\Sh4ObjTest\Parser\ObjectData;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation;
use Lhsazevedo\Sh4ObjTest\Parser\LocalRelocationShort;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExportSymbol;

function bitf(int $bitfield, int $position, int $length): int
{
    $bitfield >>= (8 - $position - $length);

    $mask = (1 << $length) - 1;

    return $bitfield & $mask;
}

class SectionHeader extends Base
{
    public const CONTENTS_CODE = 0;
    public const CONTENTS_DATA = 1;
    public const CONTENTS_STACK = 2;
    public const CONTENTS_DUMMY = 3;
    public const CONTENTS_SPECIAL = 4;
    public const CONTENTS_NONSPEC = 0;

    public readonly int $format;
    public readonly int $address;
    public readonly int $length;
    public readonly int $alignment;

    public readonly int $contents;
    public readonly int $concat;

    public readonly int $read;
    public readonly int $write;
    public readonly int $exec;
    public readonly int $init;

    public readonly int $flags3;
    public readonly string $name;

    public ?int $linkedAddress = null;

    /** @var ObjectData[] */
    public array $objectDataEntries = [];

    /** @var Relocation[] */
    public array $relocations = [];

    /** @var LocalRelocationLong[] */
    public array $localRelocationsLong = [];

    /** @var LocalRelocationShort[] */
    public array $localRelocationsShort = [];

    /** @var ExportSymbol[] */
    public array $exports = [];

    public function __construct(BinaryReader $reader)
    {
        $bitfield = $reader->readUInt8();
        $this->format = bitf($bitfield, 0, 2);

        $this->address = $reader->readUInt32BE();
        $this->length = $reader->readUInt32BE();
        $this->alignment = $reader->readUInt32BE();

        $flags1 = $reader->readUInt8();
        $this->contents = bitf($flags1, 0, 4);
        $this->concat = bitf($flags1, 4, 4);

        $rwx = $reader->readUInt8();
        $this->read = bitf($rwx, 0, 2);
        $this->write = bitf($rwx, 2, 2);
        $this->exec = bitf($rwx, 4, 2);
        $this->init = bitf($rwx, 6, 2);

        $this->flags3 = $reader->readUInt8();
        $this->name = $reader->readBytes($reader->readInt8());
    }
    
    public function addRelocation(Relocation $relocation): void
    {
        $this->relocations[] = $relocation;
    }

    public function addLocalRelocationLong(LocalRelocationLong $localRelocationLong): void
    {
        $this->localRelocationsLong[] = $localRelocationLong;
    }

    public function addLocalRelocationShort(LocalRelocationShort $localRelocationShort): void
    {
        $this->localRelocationsShort[] = $localRelocationShort;
    }

    public function addObjectData(ObjectData $objectData): void
    {
        $this->objectDataEntries[] = $objectData;
    }

    public function assembleObjectData(): string
    {
        $data = '';

        foreach ($this->objectDataEntries as $objectDataEntry) {
            $currentLength = strlen($data);

            if ($objectDataEntry->address > $currentLength) {
                $data = str_pad($data, $objectDataEntry->address, "\0", STR_PAD_RIGHT);
            } elseif ($objectDataEntry->address < $currentLength) {
                throw new \Exception("Unexpected object address 0x" . dechex($objectDataEntry->address) . ", current length is 0x" . dechex($currentLength), 1);
            }

            $data .= $objectDataEntry->data;
        }

        return $data;
    }


    public function rellocate(int $address): void
    {
        $this->linkedAddress = $this->address + $address;

        foreach ($this->relocations as $relocation) {
            $relocation->rellocate($this->linkedAddress);
        }

        foreach ($this->exports as $export) {
            $export->rellocate($this->linkedAddress);
        }
    }

    public function addExport(ExportSymbol $export): void
    {
        $this->exports[] = $export;
    }

    public function findExportedSymbol(string $name): ?ExportSymbol
    {
        foreach ($this->exports as $export) {
            if ($export->name === $name) {
                return $export;
            }
        }

        return null;
    }

    public function findExportedAddress(int $address): ?ExportSymbol
    {
        foreach ($this->exports as $export) {
            if ($export->linkedAddress === $address) {
                return $export;
            }
        }

        return null;
    }
}
