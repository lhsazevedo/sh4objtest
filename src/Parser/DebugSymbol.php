<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

use Lhsazevedo\Sh4ObjTest\BinaryReader;

/**
 * A single debug symbol ("dsy", chunk type 0x34). Each chunk holds exactly one
 * record. We decode the fields useful for coverage (kind, name, storage,
 * section/address) and skip the advanced descriptive sub-grammars (member /
 * enum / equate field layouts) — only consuming their bytes to stay in sync.
 *
 * Grammar reference (SYSROF "dsy"): the leading byte packs the 7-bit symbol
 * type and a 1-bit assignment flag (type = byte >> 1, assign = byte & 1).
 */
class DebugSymbol
{
    /** SH4 address size, used for the addrsize-width fields below. */
    private const ADDR_SIZE = 4;

    // Assignment (storage) kinds.
    private const AINFO_REG            = 1;
    private const AINFO_STATIC_EXT_DEF = 2;
    private const AINFO_STATIC_EXT_REF = 3;
    private const AINFO_STATIC_INT     = 4;
    private const AINFO_STATIC_COM     = 5;
    private const AINFO_AUTO           = 6;
    private const AINFO_CONST          = 7;

    public ?Stype $type;

    public int $rawType;

    public bool $assign;

    public int $id;

    public string $name;

    public int $nesting;

    public ?int $ainfo = null;

    public ?int $dataLength = null;

    public ?int $section = null;

    public ?int $address = null;

    public ?string $register = null;

    public ?string $externalName = null;

    public ?string $constant = null;

    public int $fileNumber;

    public int $lineNumber;

    public bool $variadic = false;

    /**
     * True for a symbol with a internal linkable address
     */
    public function isStaticDefinition(): bool
    {
        return in_array($this->ainfo, [
            self::AINFO_STATIC_EXT_DEF,
            self::AINFO_STATIC_INT,
            self::AINFO_STATIC_COM,
        ], true)
            && $this->section !== null
            && $this->address !== null;
    }

    public function linkedName(): string
    {
        return $this->externalName ?? ('_' . $this->name);
    }

    public function __construct(BinaryReader $reader)
    {
        $typeByte = $reader->readUInt8();
        $this->rawType = $typeByte >> 1;
        $this->type = Stype::tryFrom($this->rawType);
        $this->assign = ($typeByte & 1) === 1;

        $this->id = $reader->readUInt16BE();
        $this->name = $reader->readBytes($reader->readUInt8());
        $this->nesting = $reader->readUInt16BE();

        if ($this->assign) {
            $this->ainfo = $reader->readUInt8();
            $this->dataLength = $reader->readUInt32BE();

            if (in_array($this->ainfo, [
                self::AINFO_STATIC_EXT_DEF,
                self::AINFO_STATIC_INT,
                self::AINFO_STATIC_COM,
            ], true)) {
                $this->section = $reader->readUInt16BE();
            }

            if (in_array($this->ainfo, [
                self::AINFO_STATIC_EXT_DEF,
                self::AINFO_STATIC_INT,
                self::AINFO_STATIC_COM,
                self::AINFO_AUTO,
            ], true)) {
                $this->address = $reader->readUInt32BE();
            }

            if ($this->ainfo === self::AINFO_REG) {
                $this->register = $reader->readBytes($reader->readUInt8());
            }

            if ($this->ainfo === self::AINFO_STATIC_EXT_DEF
                || $this->ainfo === self::AINFO_STATIC_EXT_REF) {
                $this->externalName = $reader->readBytes($reader->readUInt8());
            }

            if ($this->ainfo === self::AINFO_CONST) {
                $this->constant = $reader->readBytes($reader->readUInt8());
            }
        }

        // Advanced descriptive sub-grammars. We don't surface these yet, but
        // must consume their bytes so the trailing sfn/sln line up.
        switch ($this->type) {
            case Stype::Member:
                $bitunit = ($reader->readUInt8() >> 7) & 1;
                $reader->eat(self::ADDR_SIZE * 2); // field length + offset
                if ($bitunit) {
                    $reader->eat(self::ADDR_SIZE); // bit offset
                }
                break;

            case Stype::Enum:
                $reader->eat(1 + 4); // value length + value
                break;

            case Stype::Const:
                $reader->readBytes($reader->readUInt8()); // value
                break;

            case Stype::Equate:
                $reader->eat(1 + 4 + 1 + self::ADDR_SIZE + 1); // qvallen, qvalue, btype, sizeinfo, sign/flt
                break;

            default:
                break;
        }

        $this->fileNumber = $reader->readUInt16BE(); // sfn
        $this->lineNumber = $reader->readUInt16BE(); // sln
        $reader->readUInt16BE();                     // negotiation number

        if ($this->type === Stype::Tag) {
            $reader->readUInt8(); // magic
        }

        if ($this->type === Stype::Parameter) {
            $this->variadic = $reader->readUInt8() === 1;
            $flags = $reader->readUInt8();

            if ($flags & 0x80) {
                $this->register = $reader->readBytes($reader->readUInt8());
            }
        }
    }
}
