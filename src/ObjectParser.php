<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim;

use Lhsazevedo\Objsim\Parser\ChunkType;
use Lhsazevedo\Objsim\Parser\Chunks\Module;
use Lhsazevedo\Objsim\Parser\Chunks\Unit;
use Lhsazevedo\Objsim\Parser\ObjectData;

class Chunk {
    public ChunkType $type;
    public bool $continuation;

    public function __construct(
        public int $ukn,
        int $type,
        public int $len
    ) {
        $this->continuation = ($type & 0x80) === 1;

        // TODO: Use chunk classes

        $this->type = match ($type & 0x7f) {
            0x04 => ChunkType::ModuleHeader,
            0x06 => ChunkType::UnitHeader,
            0x07 => ChunkType::UnitDebug,
            0x08 => ChunkType::Section,
            0x0c => ChunkType::Imports,
            0x14 => ChunkType::Exports,
            0x1a => ChunkType::SectionSelection,
            0x1c => ChunkType::ObjectData,
            0x20 => ChunkType::Relocation,
            0x7f => ChunkType::Termination,
            default => ChunkType::Unknown,
        };
    }
}

readonly class ImportSymbol
{
    public function __construct(
        public string $name,
        public int $type,
    ) {}
}

readonly class ExportSymbol
{
    public function __construct(
        public string $name,
        public int $section,
        public int $type,
        public int $offset,
    ) {}
}

readonly class Relocation{
    public function __construct(
        public int $flags,
        public int $address,
        public int $bitloc,
        public int $flen,
        public int $bcount,
        public int $operator,
        public int $section,
        public int $opcode,
        public int $addendLen,
        public int $relLen,
        public int $ukn1,
        public int $ukn2,
        public int $importIndex,
        public int $ukn3,
        public string $name,
    ) {
        if ($operator != 8) {
            throw new \Exception("Unsupported relocation operator", 1);
        }
    }
}

readonly class ParsedObject {
    public function __construct(
        // public array $imports,

        /** @var ExportSymbol[] */
        public array $exports,

        /** @var Relocation[] */
        public array $relocations,

        public string $code,
    )
    {}

    public function getRelocationAt(int $address): ?Relocation
    {
        foreach ($this->relocations as $relocation) {
            if ($relocation->address !== $address) continue;

            return $relocation;
        }

        return null;
    }
}

class ObjectParser
{
    private const MAGIC = "\x80\x21\x00\x80";

    /** @var Module[] */
    private array $modules = [];

    /** @var ImportSymbol[] */
    private array $imports = [];

    /** @var ExportSymbol[] */
    private array $exports = [];

    /** @var Relocation[] */
    private array $relocations = [];

    private function realParse($objectFile): ParsedObject
    {
        // $obj = file_get_contents($objectFile);

        /** @var Module */
        $currentModule = null;

        /** @var Unit */
        $currentUnit = null;

        $reader = new BinaryReader($objectFile);

        if ($reader->readBytes(4) !== self::MAGIC) {
            echo "Invalid magic.\n";
            exit;
        }

        $chunks = [];

        $reader->seek(0x20);

        while (!$reader->feof()) {
            $chunkBase = $reader->tell();

            // var_dump("We are at " . $chunkBase . " (" . dechex($chunkBase) . ")\n");

            $ukn = $reader->readUInt8();
            $type = $reader->readUInt8();
            $len = $reader->readUInt8();

            $chunk = new Chunk($ukn, $type, $len);
            $chunks[] = $chunk;

            switch ($chunk->type) {
                case ChunkType::ModuleHeader:
                    if ($currentModule) {
                        throw new \Exception("Multiple modules are unsupported at the moment", 1);
                    }

                    $currentModule = new Module($reader);
                    $this->modules[] = $currentModule;
                    break;

                case ChunkType::UnitHeader:
                    $currentUnit = new Unit($reader);
                    $currentModule->addUnit($currentUnit);
                    break;

                case ChunkType::Exports:
                    while($reader->tell() < $chunkBase + $len) {
                        $section = $reader->readUInt16();
                        $type = $reader->readUInt8();
                        $offset = $reader->readUInt32();
                        $name = $reader->readBytes($reader->readUInt8());

                        $this->exports[] = new ExportSymbol(
                            $name, $section, $type, $offset
                        );
                    }
                    break;

                case ChunkType::Imports:
                    while($reader->tell() < $chunkBase + $len) {
                        $type = $reader->readUInt8();
                        $name = $reader->readBytes($reader->readUInt8());

                        $this->imports[] = new ImportSymbol($name, $type);
                    }
                    break;

                case ChunkType::ObjectData:
                    $currentUnit->addObjectData(new ObjectData($reader));
                    break;

                case ChunkType::Relocation:
                    while($reader->tell() < $chunkBase + $len) {

                        $flags = $reader->readUInt8();
                        $address = $reader->readUInt32BE();
                        $bitloc = $reader->readUInt8();
                        $flen = $reader->readUInt8();
                        $bcount = $reader->readUInt8();
                        $operator = $reader->readUInt8();
                        $section = $reader->readUInt16();
                        $opcode = $reader->readUint8();
                        $addendLen = $reader->readUInt8();
                        $relLen = $reader->readUInt8();
                        $ukn1 = $reader->readUInt8();
                        $ukn2 = $reader->readUInt8();
                        $importIndex = $reader->readUInt8();
                        $ukn3 = $reader->readUInt8();

                        $name = $this->imports[$importIndex]->name;

                        $this->relocations[] = new Relocation(
                            $flags,
                            $address,
                            $bitloc,
                            $flen,
                            $bcount,
                            $operator,
                            $section,
                            $opcode,
                            $addendLen,
                            $relLen,
                            $ukn1,
                            $ukn2,
                            $importIndex,
                            $ukn3,
                            $name,
                        );
                    }
                    break;

                case ChunkType::Termination:
                    break 2;

                default:
                    # code...
                    break;
            }

            $reader->seek($chunkBase + $len);
        }

        return new ParsedObject($this->exports, $this->relocations, $this->modules[0]->units[0]->assembleObjectData());
    }

    public static function parse($objectFile): ParsedObject
    {
        return (new static())->realParse($objectFile);
    }
}
