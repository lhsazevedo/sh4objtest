<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Parser\ChunkType;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Module;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Unit;
use Lhsazevedo\Sh4ObjTest\Parser\ObjectData;

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
        public int $fieldLength,
        public int $bcount,
        public int $operator,
        public int $section,
        public int $opcode,
        public int $addendLen,
        public int $relLen,
        // public int $ukn1,
        // public int $ukn2,
        public int $importIndex,
        // public int $ukn3,
        public string $name,
        public int $offset,
    ) {
        // if ($operator != 8) {
        //     throw new \Exception("Unsupported relocation operator $operator", 1);
        // }
    }
}

class ParsedObject {
    public function __construct(
        // public array $imports,

        /** @var ExportSymbol[] */
        public array $exports,

        /** @var Relocation[] */
        public array $relocations,

        public string $code,
    )
    {}

    // Moved to Simulator
    // public function getRelocationAt(int $address): ?Relocation
    // {
    //     foreach ($this->relocations as $relocation) {
    //         if ($relocation->address !== $address) continue;

    //         return $relocation;
    //     }

    //     return null;
    // }
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
                        $offset = $reader->readUInt32BE();
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
                        // TODO: Move to Relocation

                        $flags = $reader->readUInt8();
                        $address = $reader->readUInt32BE();
                        $bitloc = $reader->readUInt8();
                        $fieldLength = $reader->readUInt8();
                        $bcount = $reader->readUInt8();
                        $operator = $reader->readUInt8();

                        if ($operator != 8) {
                            throw new \Exception("Unsupported relocation operator $operator", 1);
                        }

                        $section = $reader->readUInt16();
                        $opcode = $reader->readUint8();
                        $addendLen = $reader->readUInt8();

                        // Probably should not be determined by relocation data length
                        $relLen = $reader->readUInt8();
                        if ($relLen === 4) {
                            $maybeRelType = $reader->readUInt8();
                            if ($maybeRelType !== 2) {
                                throw new \Exception("Wrong relocation data type $type?", 1);
                            }

                            $maybeImportIndexHighNible = $reader->readUInt8();
                            if ($maybeImportIndexHighNible) {
                                echo "WARN: Value found in possible import index high nible\n";
                            }

                            $importIndex = $reader->readUInt8();
                            $name = $this->imports[$importIndex]->name;
                            $offset = 0;
                        } elseif ($relLen === 11) {
                            $maybeRelType = $reader->readUInt8();
                            if ($maybeRelType !== 3) {
                                throw new \Exception("Wrong relocation data type $type?", 1);
                            }

                            $reader->eat(4);
                            $offset = $reader->readUInt8();
                            $reader->eat(2);
                            $importIndex = $reader->readUInt8();
                            $name = $this->imports[$importIndex]->name;

                            $reader->eat(1);
                        } else {
                            throw new \Exception("Unsupported relocation length $relLen", 1);
                        }

                        $terminator = $reader->readUInt8();
                        if ($terminator !== 0xff) {
                            throw new \Exception("Wrong terminator byte 0x" . dechex($terminator), 1);
                            
                        } 

                        $this->relocations[] = new Relocation(
                            $flags,
                            $address,
                            $bitloc,
                            $fieldLength,
                            $bcount,
                            $operator,
                            $section,
                            $opcode,
                            $addendLen,
                            $relLen,
                            //$ukn1,
                            //$ukn2,
                            $importIndex,
                            //$ukn3,
                            $name,
                            $offset,
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

        // $f = fopen('php://stdout', 'r');
        // fputcsv($f, [
        //     "name",
        //     "flags",
        //     "address",
        //     "bitloc",
        //     "fieldLength",
        //     "bcount",
        //     "operator",
        //     "section",
        //     "opcode",
        //     "addendLen",
        //     "relLen",
        //     "ukn1",
        //     "ukn2",
        //     "importIndex",
        //     "ukn3",
        // ]);
        // foreach ($this->relocations as $r) {
        //     fputcsv($f, [
        //         $r->name,
        //         dechex($r->flags),
        //         dechex($r->address),
        //         $r->bitloc,
        //         $r->fieldLength,
        //         $r->bcount,
        //         dechex($r->operator),
        //         $r->section,
        //         dechex($r->opcode),
        //         $r->addendLen,
        //         $r->relLen,
        //         $r->ukn1,
        //         $r->ukn2,
        //         dechex($r->importIndex),
        //         $r->ukn3,
        //     ]);
        // }
        // exit;

        return new ParsedObject($this->exports, $this->relocations, $this->modules[0]->units[0]->assembleObjectData());
    }

    public static function parse($objectFile): ParsedObject
    {
        return (new static())->realParse($objectFile);
    }
}
