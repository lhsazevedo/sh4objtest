<?php

declare(strict_types=1);

require_once "BinaryReader.php";

enum ChunkType {
    case ModuleHeader;
    case UnitHeader;
    case UnitDebug;
    case Section;
    case Imports;
    case Exports;
    case SectionSelection;
    case ObjectData;
    case Relocation;
    case Termination;
    case Uknown;
}

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
            default => ChunkType::Uknown,
        };
    }
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
        public int $int,
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
    ) { }
}

readonly class ParsedObject {
    public function __construct(
        // public array $imports,
        /** @var ExportSymbol[] */
        public array $exports,
        // public array $relocations,
        public string $code,
    )
    {}
}

class ObjectParser
{
    private const MAGIC = "\x80\x21\x00\x80";

    private string $code = '';

    /** @var ExportSymbol[] */
    private array $exports = [];

    /** @var Relocation[] */
    private array $relocations = [];

    private function realParse($objectFile): ParsedObject
    {
        // $obj = file_get_contents($objectFile);

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
                    # code...
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

                case ChunkType::ObjectData:
                    $reader->eat(1);
                    $addr = $reader->readUInt32();
                    $objLen = $reader->readUInt8();

                    $code = $reader->readBytes($objLen);
                    $this->code .= $code;
                    break;
                
                case ChunkType::Relocation:
                    while($reader->tell() < $chunkBase + $len) {
                        $flags = $reader->readUInt8();
                        $int = $reader->readUInt32();
                        $bitloc = $reader->readUInt8();
                        $flen = $reader->readUInt8();
                        $bcount = $reader->readUInt8();
                        $operator = $reader->readUInt8();
                        $section = $reader->readUInt16();
                        $opcode = $reader->readUInt16();
                        $addendLen = $reader->readUInt8();
                        $relLen = $reader->readUInt8();
                        $ukn1 = $reader->readUInt8();
                        $ukn2 = $reader->readUInt8();
                        $importIndex = $reader->readUInt8();
                        $ukn3 = $reader->readUInt8();

                        $this->relocations[] = new Relocation(
                            $flags,
                            $int,
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

        var_dump($this->relocations);
        exit;

        return new ParsedObject($this->exports, $this->code);
    }

    public static function parse($objectFile): ParsedObject
    {
        return (new static())->realParse($objectFile);
    }
}
