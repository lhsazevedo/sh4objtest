<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

use Lhsazevedo\Sh4ObjTest\BinaryReader;

class FileHeader extends Base
{
    public int $format;
    public bool $littleEndian;
    public string $date;
    public int $unitCount;
    public string $version;
    public int $addrBitSize;
    public bool $segmented;
    public int $fieldSize;
    public string $moduleName;
    public string $cpuName;

    public function __construct(BinaryReader $reader)
    {
        $fmt = $reader->readUInt8();
        $this->littleEndian = (($fmt >> 3) & 1) === 1;
        $this->format       = ($fmt & 0xF0) >> 4;

        $this->date      = $reader->readBytes(12);
        $this->unitCount = $reader->readUInt16BE();
        $reader->readUInt8(); // code type (must be 0 = ASCII)

        $this->version     = $reader->readBytes(4);
        $this->addrBitSize = $reader->readUInt8();
        $segfield          = $reader->readUInt8();
        $reader->eat(4); // reserved

        $this->segmented = (($segfield >> 7) & 1) === 1;
        $this->fieldSize = ($segfield >> 3) & 0xF;

        // OS name: slen=0 or slen>=0x80 both mean "absent"; always consumes 1 placeholder byte
        $slen = $reader->readUInt8();
        ($slen > 0 && $slen < 0x80) ? $reader->eat($slen) : $reader->eat(1);

        $slen             = $reader->readUInt8();
        $this->moduleName = $slen > 0 ? $reader->readBytes($slen) : '';

        $slen          = $reader->readUInt8();
        $this->cpuName = $slen > 0 ? $reader->readBytes($slen) : '';
    }
}
