<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Parser\ChunkType;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ModuleHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;
use Lhsazevedo\Sh4ObjTest\Parser\ObjectData;
use Lhsazevedo\Sh4ObjTest\Parser\LocalRelocationLong;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation;
use Lhsazevedo\Sh4ObjTest\Parser\LocalRelocationShort;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExportSymbol;

function hexpad(string $hex, int $len): string
{
    return str_pad($hex, $len, '0', STR_PAD_LEFT);
}

function xdump(string $data): void
{
    $data = bin2hex($data);
    $data = str_split($data, 2);
    $data = array_chunk($data, 16);

    foreach ($data as $i => $v) {
        $ascii = [];
        foreach ($v as $hex) {
            $ascii[] = ctype_print(hex2bin($hex)) ? chr(hexdec($hex)) : '.';
        }
        echo '0x' . hexpad(dechex($i * 16), 4) . ': ' . str_pad(join(' ', $v), 47) . ' | ' . join('', $ascii) . "\n";
    }
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
            0x08 => ChunkType::SectionHeader,
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

class ParsedObject {
    public function __construct(
        public UnitHeader $unit,
    )
    {}
}

final class ObjectParser
{
    private const MAGIC = "\x80\x21\x00\x80";

    /** @var ModuleHeader[] */
    private array $modules = [];

    /** @var ImportSymbol[] */
    private array $imports = [];

    private function realParse(string $objectFile): ParsedObject
    {
        // $obj = file_get_contents($objectFile);

        /** @var ?ModuleHeader */
        $currentModule = null;

        /** @var ?UnitHeader */
        $currentUnit = null;

        /** @var ?SectionHeader */
        $currentSection = null;

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

                    $currentModule = new ModuleHeader($reader);
                    $this->modules[] = $currentModule;
                    break;

                case ChunkType::UnitHeader:
                    if ($currentUnit) {
                        throw new \Exception("Multiple units are unsupported at the moment", 1);
                    }
                    if (!$currentModule) {
                        throw new \Exception("Invalid SysRof: Unit without module", 1);
                    }
                    $currentUnit = new UnitHeader($reader);
                    $currentModule->addUnit($currentUnit);
                    break;

                case ChunkType::SectionHeader:
                    if (!$currentModule) {
                        throw new \Exception("Invalid SysRof: Section without unit", 1);
                    }
                    $currentSection = new SectionHeader($reader);
                    $currentUnit->addSection($currentSection);

                case ChunkType::Exports:
                    while($reader->tell() < $chunkBase + $len) {
                        $section = $reader->readUInt16BE();
                        $type = $reader->readUInt8();
                        $offset = $reader->readUInt32BE();

                        // TODO: Extract to a ChunkReader or ObjectReader class
                        if ($reader->tell() >= $chunkBase + $len) {
                            $chunkBase = $reader->tell();
                            $ukn = $reader->readUInt8();
                            $type = $reader->readUInt8();
                            $len = $reader->readUInt8();
                        }

                        $name = $reader->readBytes($reader->readUInt8());

                        $currentUnit->sections[$section]->addExport(new ExportSymbol(
                            $name, $section, $type, $offset
                        ));
                    }
                    break;

                case ChunkType::Imports:
                    while($reader->tell() < $chunkBase + $len) {
                        $type = $reader->readUInt8();

                        // TODO: Extract to a ChunkReader or ObjectReader class
                        // It is possible that the contents are split into multiple chunks
                        // so we need to check if we are at the end of the current chunk.
                        // Ideally, there should be a class that abstract this away.
                        if ($reader->tell() >= $chunkBase + $len) {
                            $chunkBase = $reader->tell();
                            $ukn = $reader->readUInt8();
                            $type = $reader->readUInt8();
                            $len = $reader->readUInt8();
                        }

                        $name = $reader->readBytes($reader->readUInt8());

                        $this->imports[] = new ImportSymbol($name, $type);
                    }
                    break;

                case ChunkType::ObjectData:
                    $currentSection->addObjectData(new ObjectData($reader));
                    break;

                case ChunkType::Relocation:
                    while($reader->tell() < $chunkBase + $len) {
                        // TODO: Move to Relocation
                        $raw = $reader->peekBytes(14);

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
                        $opcode = $reader->readUInt8();
                        $addendLen = $reader->readUInt8();

                        // Probably should not be determined by relocation data length
                        $relLen = $reader->readUInt8();
                        $raw .= $reader->peekBytes($relLen);
                        //xdump($raw);
                        if ($relLen === 4) {
                            $maybeRelType = $reader->readUInt8();
                            if ($maybeRelType === 2) {
                                // External Symbol Relocation
                                $maybeImportIndexHighNible = $reader->readUInt8();
                                if ($maybeImportIndexHighNible) {
                                    echo "WARN: Value found in possible import index high nible\n";
                                }

                                $importIndex = $reader->readUInt8();

                                if ($importIndex >= count($this->imports)) {
                                    echo "Import index $importIndex out of bounds\n";
                                    $terminator = $reader->readUInt8();
                                    if ($terminator !== 0xff) {
                                        throw new \Exception("Wrong terminator byte 0x" . dechex($terminator), 1);
                                    }

                                    continue;
                                }
                                $name = $this->imports[$importIndex]->name;
                                $offset = 0;
                            } else if ($maybeRelType === 0) {
                                // Internal Address Relocation (short form, data in object code)
                                $sectionIndex = $reader->readUInt16BE();
                                $currentSection->addLocalRelocationShort(new LocalRelocationShort(
                                    $sectionIndex,
                                    $address,
                                ));

                                $terminator = $reader->readUInt8();
                                if ($terminator !== 0xff) {
                                    throw new \Exception("Wrong terminator byte 0x" . dechex($terminator), 1);
                                }

                                continue;
                            } else {
                                echo "WARN: Wrong relocation data type for relLen 4: $maybeRelType?\n";
                            }
                        } elseif ($relLen === 11) {
                            $maybeRelType = $reader->readUInt8();
                            if ($maybeRelType === 3) {
                                // External Symbol Offset Relocation
                                $reader->eat(4);
                                $offset = $reader->readUInt8();
                                $reader->eat(2);
                                $importIndex = $reader->readUInt8();
                                $name = $this->imports[$importIndex]->name;

                                $reader->eat(1);
                            } else if ($maybeRelType === 0) {
                                // Internal Address Relocation (long form, data in relocation)

                                // Unknown
                                $reader->eat(1);

                                $sectionIndex = $reader->readUInt8();

                                // Unknown, usually 03 04
                                $reader->eat(2);

                                $target = $reader->readUInt32BE();
                                $reader->eat(1);

                                $terminator = $reader->readUInt8();
                                if ($terminator !== 0xff) {
                                    throw new \Exception("Wrong terminator byte 0x" . dechex($terminator), 1);
                                }

                                // TODO: Fix this code flow, probably by adding
                                // classes for different kinds of relocation
                                // TODO: 
                                $currentSection->addLocalRelocationLong(new LocalRelocationLong(
                                    $sectionIndex,
                                    $address,
                                    $target
                                ));
                                continue;
                            } else {
                                echo "WARN: Wrong relocation data type for relLen 11: $maybeRelType?\n";
                            }
                        } else {
                            throw new \Exception("Unsupported relocation length $relLen", 1);
                        }

                        $terminator = $reader->readUInt8();
                        if ($terminator !== 0xff) {
                            throw new \Exception("Wrong terminator byte 0x" . dechex($terminator), 1);
                        }

                        $relocation = new Relocation(
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
                        $currentSection->addRelocation($relocation);
                    }
                    break;

                case ChunkType::SectionSelection:
                    $unitIndex = $reader->readUInt16BE();
                    $sectionIndex = $reader->readUInt16BE();

                    $currentSection = $this->modules[0]->units[$unitIndex]->sections[$sectionIndex];
                    break;

                case ChunkType::Termination:
                    break 2;

                default:
                    if (($type & 0x7f) === 0x07) {
                        // TODO: Negotiation number
                        break;
                    }

                    // echo "WARN: Unknown chunk type " . dechex($type) . "\n";
                    // xdump($reader->readBytes($len - 3));
                    //throw new \Exception("Unknown chunk type " . dechex($type), 1);
                    break;
            }

            $reader->seek($chunkBase + $len);
        }

        return new ParsedObject($this->modules[0]->units[0]);
    }

    public static function parse(string $objectFile): ParsedObject
    {
        return (new static())->realParse($objectFile);
    }
}
