<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

use Lhsazevedo\Sh4ObjTest\BinaryReader;

class ModuleHeader extends Base
{
    const TYPE_ABS_ML = 0;
    const TYPE_REL_ML = 1;

    public int $type;

    public string $buildDate;

    public int $unitCount;

    public string $version;

    public int $addrUpdate;

    public bool $segmentId;

    public int $addressFieldLen;

    public int $spaceWithinSegment;

    public int $segmentSize;

    public int $segmentShift;

    public int $entryPoint;

    public int $unitAppearNumber;

    public int $sectionAppearNumber;

    public int $address;

    public string $osName;

    public string $sysName;

    public string $moduleName;

    public string $cpuName;

    /** @var UnitHeader[] */
    public array $units = [];

    public function __construct(BinaryReader $reader)
    {
        $this->type = $reader->readUInt8();
        $this->buildDate = $reader->readBytes(12);
        $this->unitCount = $reader->readUInt16BE();
        if ($this->unitCount > 1) {
            throw new \Exception("Multiple units are unsupported at the moment", 1);
        }

        $reader->readUInt8(); // code
        $this->version = $reader->readBytes(4);
        $this->addrUpdate = $reader->readUInt8();

        $i = $reader->readUInt8();
        $this->segmentId = ($i & 0x80) !== 0;
        $this->addressFieldLen = ($i >> 3) & 0xf;

        // TODO: Check if this is correct
        $this->spaceWithinSegment = $reader->readUInt8();
        $this->segmentSize = $reader->readUInt8();
        $this->segmentShift = $reader->readUInt8();
        $this->entryPoint = $reader->readUInt8();

        // TODO: Check if this is correct
        if ($this->entryPoint) {
            if ($this->type != self::TYPE_ABS_ML) {
                $this->unitAppearNumber = $reader->readUInt16();
                $this->sectionAppearNumber = $reader->readUInt16();
            }
            // else if (segmented)
            // segmentAddr = reader.readNext?();
            else {
                $this->address = $reader->readUInt32();
            }
        }

        if ($len = $reader->readUInt8()) {
            $this->osName = $reader->readBytes($len);
        }

        if ($len = $reader->readUInt8()) {
            $this->sysName = $reader->readBytes($len);
        }

        $this->moduleName = $reader->readBytes($reader->readUInt8());
        $this->cpuName = $reader->readBytes($reader->readUInt8());
    }

    public function addUnit(UnitHeader $unit): void
    {
        if (count($this->units) > $this->unitCount) {
            throw new \Exception("Number of units greater than module unit count", 1);
            
        }

        $this->units[] = $unit;
    }
}
