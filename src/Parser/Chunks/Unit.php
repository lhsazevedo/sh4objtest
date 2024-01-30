<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

use Lhsazevedo\Sh4ObjTest\BinaryReader;
use Lhsazevedo\Sh4ObjTest\Parser\ObjectData;

class Unit extends Base
{
    public int $format;

    public int $nSections;

    public int $nExtRefs;

    public int $nExtDefs;

    public string $unitName;

    public string $toolName;

    public string $toolDate;

    /** @var ObjectData[] */
    public array $objectDataEntries = []; 

    public function __construct(BinaryReader $reader)
    {
        $this->format = $reader->readUInt8() & 3;
        $this->nSections = $reader->readUInt16();
        $this->nExtRefs = $reader->readUInt16();
        $this->nExtDefs = $reader->readUInt16();
        $this->unitName = $reader->readBytes($reader->readUInt8());
        $this->toolName = $reader->readBytes($reader->readUInt8());
        $this->toolDate = $reader->readBytes(12);

        // TODO
        //linkerName = $reader->readBytes($reader->readUInt8());
        //linkerDate = $reader->readBytes(12);
    }

    public function addObjectData(ObjectData $objectData)
    {
        $this->objectDataEntries[] = $objectData;
    }

    public function assembleObjectData()
    {
        $data = '';

        foreach ($this->objectDataEntries as $objectDataEntry) {
            $currentLength = strlen($data);

            if ($objectDataEntry->address > $currentLength) {
                $data = str_pad($data, $objectDataEntry->address, "\0", STR_PAD_RIGHT);
            } elseif ($objectDataEntry->address < $currentLength) {
                throw new \Exception("Unexpected object address", 1);
            }

            $data .= $objectDataEntry->data;
        }

        return $data;
    }
}