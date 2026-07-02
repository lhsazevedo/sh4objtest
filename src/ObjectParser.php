<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Parser\Chunk;
use Lhsazevedo\Sh4ObjTest\Parser\ChunkType;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ModuleHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;
use Lhsazevedo\Sh4ObjTest\Parser\ObjectData;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExternalRelocation;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\InternalRelocation;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExportSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\ImportSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\DebugLine;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;

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

final class ObjectParser
{
    private const MAGIC = "\x80\x21\x00\x80";

    /**
     * Relocation value-expression opcodes.
     *
     * A relocation value is a postfix (RPN) expression: operand pushes
     * followed by ADD/SUB combinators, terminated by END. Evaluating it
     * yields one symbol operand plus a signed addend.
     */
    private const REL_PUSH_SECTION = 0x00; // operand: u16 section index
    private const REL_PUSH_IMPORT  = 0x02; // operand: u16 import index
    private const REL_PUSH_LITERAL = 0x03; // operand: u8 byte-size, then literal
    private const REL_ADD          = 0x20;
    private const REL_SUB          = 0x21;
    private const REL_END          = 0xFF;

    /** @var ModuleHeader[] */
    private array $modules = [];

    /** @var ImportSymbol[] */
    private array $imports = [];

    /**
     * Read the raw file bytes, validate magic, then join continuation chunks
     * into logical records.
     *
     * @return Chunk[]
     */
    private function frameChunks(string $bytes): array
    {
        if (substr($bytes, 0, 4) !== self::MAGIC) {
            echo "Invalid magic.\n";
            exit;
        }

        $len = strlen($bytes);
        $pos = 0;
        $chunks = [];

        /** @var array{type:int,data:string,offset:int}|null */
        $pending = null;

        while ($pos < $len) {
            $type   = ord($bytes[$pos]);
            $chunkLen = ord($bytes[$pos + 1]);

            if ($chunkLen < 3 || $pos + $chunkLen > $len) {
                throw new \Exception(sprintf(
                    "Invalid chunk length %d at offset 0x%x", $chunkLen, $pos
                ));
            }

            $sum = 0;
            for ($i = 0; $i < $chunkLen; $i++) {
                $sum += ord($bytes[$pos + $i]);
            }
            if (($sum & 0xff) !== 0xff) {
                throw new \Exception(sprintf(
                    "Checksum error at offset 0x%x (type=0x%02x)", $pos, $type
                ));
            }

            $final   = ($type & 0x80) !== 0;
            $t       = $type & 0x7f;
            $content = substr($bytes, $pos + 2, $chunkLen - 3);

            if ($pending === null) {
                $pending = ['type' => $t, 'data' => $content, 'offset' => $pos];
            } else {
                $pending['data'] .= $content;
            }

            if ($final) {
                $chunks[] = new Chunk($pending['type'], $pending['data'], $pending['offset']);
                $pending = null;
            }

            $pos += $chunkLen;
        }

        return $chunks;
    }

    private function realParse(string $objectFile): ParsedObject
    {
        $bytes = file_get_contents($objectFile);
        if ($bytes === false) {
            throw new \RuntimeException("Could not open file: $objectFile");
        }

        /** @var ?ModuleHeader */
        $currentModule = null;

        /** @var ?UnitHeader */
        $currentUnit = null;

        /** @var ?SectionHeader */
        $currentSection = null;

        /** @var array<int,int> Count of skipped (unhandled) chunks, keyed by raw chunk type */
        $skippedChunkTypes = [];

        foreach ($this->frameChunks($bytes) as $chunk) {
            $reader = new BinaryReader($chunk->data);

            switch ($chunk->type) {
                case ChunkType::FileHeader:
                    // Opaque header
                    $reader->eatRest();
                    break;

                case ChunkType::ModuleHeader:
                    if ($currentModule) {
                        throw new \Exception("Multiple modules are unsupported", 1);
                    }

                    $currentModule = new ModuleHeader($reader);
                    $this->modules[] = $currentModule;
                    break;

                case ChunkType::UnitHeader:
                    if ($currentUnit) {
                        throw new \Exception("Multiple units are unsupported", 1);
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
                    break;

                case ChunkType::Exports:
                    while (!$reader->feof()) {
                        $section = $reader->readUInt16BE();
                        $type = $reader->readUInt8();
                        $offset = $reader->readUInt32BE();
                        $name = $reader->readBytes($reader->readUInt8());

                        $currentUnit->sections[$section]->addExport(new ExportSymbol(
                            $name, $section, $type, $offset
                        ));
                    }
                    break;

                case ChunkType::Imports:
                    while (!$reader->feof()) {
                        $type = $reader->readUInt8();
                        $name = $reader->readBytes($reader->readUInt8());

                        $this->imports[] = new ImportSymbol($name, $type);
                    }
                    break;

                case ChunkType::ObjectData:
                    while (!$reader->feof()) {
                        $currentSection->addObjectData(new ObjectData($reader));
                    }
                    break;

                case ChunkType::Relocation:
                    while (!$reader->feof()) {
                        $this->parseRelocation($reader, $currentSection);
                    }
                    break;

                case ChunkType::SectionSelection:
                    $unitIndex = $reader->readUInt16BE();
                    $sectionIndex = $reader->readUInt16BE();

                    $currentSection = $this->modules[0]->units[$unitIndex]->sections[$sectionIndex];
                    break;

                case ChunkType::DebugLines:
                    $nLines = $reader->readUInt16BE();
                    for ($li = 0; $li < $nLines; $li++) {
                        $fileNumber    = $reader->readUInt16BE();
                        $lineNumber    = $reader->readUInt16BE();
                        $sectionNumber = $reader->readUInt16BE();
                        $fromAddress   = $reader->readUInt32BE();
                        $toAddress     = $reader->readUInt32BE();
                        $callCount     = $reader->readUInt16BE();

                        // Each call emitted on this source line is followed by a
                        // 4-byte call-site address. These trailing entries are
                        // part of the record and must be consumed, otherwise the
                        // stream desyncs for every subsequent debug line.
                        $callSites = [];
                        for ($ci = 0; $ci < $callCount; $ci++) {
                            $callSites[] = $reader->readUInt32BE();
                        }

                        $currentUnit->addDebugLine(new DebugLine(
                            fileNumber:    $fileNumber,
                            lineNumber:    $lineNumber,
                            sectionNumber: $sectionNumber,
                            fromAddress:   $fromAddress,
                            toAddress:     $toAddress,
                            callCount:     $callCount,
                            callSites:     $callSites,
                        ));
                    }

                    // The record list is followed by a fixed 2-byte footer,
                    // consistently 0x10 0x01 across observed objects. Consume and
                    // assert it so we notice if the assumption ever breaks.
                    $footer = $reader->readUInt16BE();
                    if ($footer !== 0x1001) {
                        printf("WARN: Unexpected DebugLines footer 0x%04x\n", $footer);
                    }
                    break;

                case ChunkType::DebugSymbol:
                    $currentUnit->addDebugSymbol(new DebugSymbol($reader));
                    break;

                case ChunkType::DebugSourceFiles:
                    // "dus": negotiation number, then the source/include file
                    // table. Each entry is a drb/spare flag byte followed by a
                    // length-prefixed path; when the directory-reference bit is
                    // set it also carries a 2-byte directory appearance number.
                    $reader->readUInt16BE(); // negotiation number (efn)
                    $nFiles = $reader->readUInt16BE();
                    for ($fi = 0; $fi < $nFiles; $fi++) {
                        $drb = ($reader->readUInt8() >> 7) & 1;
                        $currentUnit->addSourceFile($reader->readBytes($reader->readUInt8()));
                        if ($drb) {
                            $reader->readUInt16BE(); // directory appearance number
                        }
                    }

                    // Optional trailing directory table. Compiler-produced "dus"
                    // chunks end with a 2-byte directory count (0 when unused),
                    // but assembler-produced ones (e.g. *_src.obj) omit it
                    // entirely and the chunk ends right after the last path. Only
                    // read the table when bytes remain; the leftover-bytes check
                    // below still flags any layout we didn't fully account for.
                    if ($reader->remaining() >= 2) {
                        $nDirs = $reader->readUInt16BE();
                        for ($di = 0; $di < $nDirs; $di++) {
                            $reader->readBytes($reader->readUInt8());
                        }
                    }
                    break;

                case ChunkType::Termination:
                    break 2;

                default:
                    // Unknown/unhandled chunk types are skipped; the inspect
                    // command surfaces them via ParsedObject::$skippedChunkTypes.
                    $reader->eatRest();
                    $skippedChunkTypes[$chunk->rawType] =
                        ($skippedChunkTypes[$chunk->rawType] ?? 0) + 1;
                    break;
            }

            if (!$reader->feof()) {
                printf(
                    "WARN: Chunk %s left %d unconsumed byte(s) at file offset 0x%x\n",
                    $chunk->type->name, $reader->remaining(), $chunk->offset
                );
            }
        }

        ksort($skippedChunkTypes);

        return new ParsedObject($this->modules[0]->units[0], $skippedChunkTypes);
    }

    public static function parse(string $objectFile): ParsedObject
    {
        return (new static())->realParse($objectFile);
    }

    /**
     * Parse one relocation record and attach it to its section.
     *
     * Layout:
     *   attributes  u8     bit 0 set => addend lives in the expression,
     *                      bits 4-6 = field-descriptor length code
     *   address     u32    offset of the patched field within the section
     *   descriptor  bytes  ((code + 1) * 2) bytes describing which bits of the
     *                      target word get patched (length tracks field width:
     *                      8 bytes for a 32-bit .DATA.L, 4 for a 16-bit .DATA.W)
     *   exprLen     u8     byte length of the value expression
     *   expression  bytes  postfix value expression, terminated by 0xFF
     */
    private function parseRelocation(BinaryReader $reader, SectionHeader $currentSection): void
    {
        $attributes = $reader->readUInt8();
        $address = $reader->readUInt32BE();

        $descriptorLen = ((($attributes >> 4) & 7) + 1) * 2;
        $reader->readBytes($descriptorLen); // field descriptor; unused downstream

        $exprLen = $reader->readUInt8();
        $exprStart = $reader->tell();

        $value = $this->evaluateRelocationExpression($reader);

        // Check we consumed the expected expression length.
        $consumed = $reader->tell() - $exprStart;
        if ($consumed !== $exprLen) {
            throw new \Exception("Relocation expression consumed $consumed bytes, expected $exprLen");
        }

        if (isset($value['section'])) {
            // Attribute bit 0 set => explicit addend (RELA), clear => in-place (REL).
            $explicitAddend = (bool) ($attributes & 1);
            $currentSection->addInternalRelocation(new InternalRelocation(
                sectionIndex: $value['section'],
                address: $address,
                addend: $explicitAddend ? $value['addend'] : null,
            ));
            return;
        }

        if (isset($value['import'])) {
            $import = $this->imports[$value['import']] ?? null;
            if ($import === null) {
                throw new \Exception("Import index {$value['import']} out of bounds");
            }

            $currentSection->addExternalRelocation(new ExternalRelocation(
                address: $address,
                name: $import->name,
                addend: $value['addend'],
                attributes: $attributes,
                fieldWidth: intdiv($descriptorLen, 2),
            ));
            return;
        }

        throw new \Exception(sprintf(
            "Relocation at 0x%08x has no symbol operand (addend %d)",
            $address, $value['addend'],
        ));
    }

    /**
     * Evaluate a relocation value expression into a single term: an addend plus
     * an optional symbol reference keyed by kind ('section' or 'import').
     *
     * @return array{section?: int, import?: int, addend: int}
     */
    private function evaluateRelocationExpression(BinaryReader $reader): array
    {
        /** @var list<array{section?: int, import?: int, addend: int}> $stack */
        $stack = [];

        while (true) {
            $op = $reader->readUInt8();

            if ($op === self::REL_END) {
                break;
            }

            switch ($op) {
                case self::REL_PUSH_SECTION:
                    $stack[] = ['section' => $reader->readUInt16BE(), 'addend' => 0];
                    break;

                case self::REL_PUSH_IMPORT:
                    $stack[] = ['import' => $reader->readUInt16BE(), 'addend' => 0];
                    break;

                case self::REL_PUSH_LITERAL:
                    $size = $reader->readUInt8();
                    if ($size !== 4) {
                        throw new \Exception("Unsupported relocation literal size $size");
                    }
                    // Unsigned: negatives arrive via SUB, not as two's-complement.
                    $stack[] = ['addend' => $reader->readUInt32BE()];
                    break;

                case self::REL_ADD:
                case self::REL_SUB:
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    if ($a === null || $b === null) {
                        throw new \Exception("Relocation expression underflow");
                    }
                    $stack[] = $this->combineRelocationTerms($a, $b, $op === self::REL_SUB);
                    break;

                default:
                    throw new \Exception(sprintf("Unknown relocation opcode 0x%02x", $op));
            }
        }

        if (count($stack) !== 1) {
            throw new \Exception("Relocation expression did not reduce to a single term");
        }

        return $stack[0];
    }

    /**
     * Combine two relocation terms with ADD/SUB. At most one operand may carry
     * a symbol; symbol-difference relocations are not supported.
     *
     * @param array{section?: int, import?: int, addend: int} $a
     * @param array{section?: int, import?: int, addend: int} $b
     * @return array{section?: int, import?: int, addend: int}
     */
    private function combineRelocationTerms(array $a, array $b, bool $subtract): array
    {
        $aSymbol = isset($a['section']) || isset($a['import']);
        $bSymbol = isset($b['section']) || isset($b['import']);

        if ($aSymbol && $bSymbol) {
            throw new \Exception("Unsupported relocation: combining two symbol operands");
        }
        if ($bSymbol && $subtract) {
            throw new \Exception("Unsupported relocation: subtracting a symbol");
        }

        // Keep the symbol-bearing operand and merge addends.
        $result = $aSymbol ? $a : $b;
        $result['addend'] = $a['addend'] + ($subtract ? -$b['addend'] : $b['addend']);

        return $result;
    }
}
