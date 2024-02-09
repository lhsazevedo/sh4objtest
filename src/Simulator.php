<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation;

function getN(int $instr): int
{
    return ($instr >> 8) & 0xf;
}

function getM(int $instr): int
{
    return ($instr >> 4) & 0xf;
}

/**
 * @return array<int,int>
 */
function getNM(int $op): array
{
    return [getN($op), getM($op)];
}

function getImm4(int $instruction): int
{
    return $instruction & 0xf;
}

function getImm8(int $instruction): int {
    return $instruction & 0xff;
}

function u2s8(int $u8): int {
    if ($u8 & 0x80) {
        return -((~$u8 & 0xff) + 1);
    }

    return $u8;
}

function getSImm8(int $instruction): int {
    return u2s8($instruction & 0xff);
}

function getSImm12(int $instruction): int {
    $value = $instruction & 0xfff;

    if ($value & 0x800) {
        return -((~$value & 0xfff) + 1);
    }

    return $value;
}

function branchTargetS8(int $instruction, int $pc): int {
    return getSImm8($instruction) * 2 + 2 + $pc;
}

function branchTargetS12(int $instruction, int $pc): int {
    return getSImm12($instruction) * 2 + 2 + $pc;
}

class Simulator
{
    private int $entryAddress;

    private bool $running = false;

    private int $pc;

    // TODO: Handle float and system registers
    /** @var int[]|Relocation[] */
    private array $registers = [
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
    ];

    // TODO: Handle float and system registers
    /** @var float[] */
    private array $fregisters = [
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
        0.0,
    ];

    private int $pr = 0;

    private int $srT = 0;

    private int $fpul = 0;

    private BinaryMemory $memory;

    /** @var AbstractExpectation[] */
    private array $pendingExpectations;

    private bool $disasm = false;

    public function __construct(
        private ParsedObject $object,

        /** @param AbstractExpectation[] */
        array $expectations,

        private Entry $entry,
        
        private bool $forceStop,
        
        /** @var TestRelocation[] */
        private array $testRelocations,
        
        /** @var MemoryInitialization[] */
        private array $initializations,

        )
    {
        $this->pendingExpectations = $expectations;

        // Search entry in exports
        /** @var ?ExportSymbol */
        $entrySymbol = map($object->exports)->find(fn (ExportSymbol $e) => $e->name === $entry->symbol);

        if (!$entrySymbol) throw new \Exception("Entry symbol $entry->symbol not found.", 1);

        $this->entryAddress = $entrySymbol->offset;
    }

    public function run(): void
    {
        $this->running = true;
        $this->pc = $this->entryAddress;
        $this->memory = new BinaryMemory(1024 * 1024 * 16);

        // Stack pointer
        $this->registers[15] = 1024 * 1024 * 16 - 1;

        // TODO: Handle other calling convetions
        foreach ($this->entry->parameters as $i => $parameter) {
            if ($i < 4) {
                $this->registers[4 + $i] = $parameter;
                continue;
            }

            // TODO: Push parameter to stack
        }

        // TODO: Handle multiple units?
        $currentSectionAddress = 0;
        foreach ($this->object->unit->sections as $section) {
            // Align
            $remainder = $currentSectionAddress % $section->alignment;
            if ($remainder) {
                $currentSectionAddress += $section->alignment - $remainder;
            }

            $section->rellocate($currentSectionAddress);

            $code = $section->assembleObjectData();
            $this->memory->writeBytes($currentSectionAddress, $code);

            foreach ($section->relocations as $relocation) {
                $relocation->rellocate($section->linkedAddress);
            }

            $currentSectionAddress += $section->length;
        }

        foreach ($this->initializations as $initialization) {
            switch ($initialization->size) {
                case 8:
                    $this->memory->writeUInt8($initialization->address, $initialization->value);
                    break;

                case 16:
                    $this->memory->writeUInt16($initialization->address, $initialization->value);
                    break;

                case 32:
                    $this->memory->writeUInt32($initialization->address, $initialization->value);
                    break;

                default:
                    throw new \Exception("Unsupported initialization size $initialization->size", 1);
            }
        }

        // TODO: Does not need to happen every run.
        foreach ($this->object->unit->sections as $section) {
            foreach ($section->localRelocationsLong as $lr) {
                $targetSection = $this->object->unit->sections[$lr->sectionIndex];

                $this->memory->writeUInt32(
                    $section->linkedAddress + $lr->address,
                    $targetSection->linkedAddress + $lr->target,
                );
            }
        }

        // TODO: Does not need to happen every run.
        // TODO: Consolidate section loop above?
        foreach ($this->object->unit->sections as $section) {
            foreach ($section->localRelocationsShort as $lr) {
                $offset = $this->memory->readUInt32($lr->address);
                $targetSection = $this->object->unit->sections[$lr->sectionIndex];

                $this->memory->writeUInt32(
                    $section->linkedAddress + $lr->address,
                    $targetSection->linkedAddress + $offset,
                );
            }
        }

        foreach ($this->object->unit->sections as $section) {
            $remainingRelocations = [];
            foreach ($section->relocations as $relocation) {
                $found = false;
    
                foreach ($this->testRelocations as $userResolution) {
                    if ($relocation->name === $userResolution->name) {
                        // FIXME: This is confusing:
                        // - Object relocation address is the address of the literal pool data item
                        // - Test relocation address is the value of the literal pool item
                        $this->memory->writeUInt32(
                            $relocation->linkedAddress,
                            $userResolution->address + $relocation->offset
                        );
                        $found = true;
                        break;
                    }
                }
    
                if (!$found) {
                    $remainingRelocations[] = $relocation;
                }
            }

            $section->relocations = $remainingRelocations;
        }


        while ($this->running) {
            $instruction = $this->readInstruction($this->pc);
            $this->log("; 0x" . dechex($this->pc) . ' ' . str_pad(dechex($instruction), 4, '0', STR_PAD_LEFT) . "    ");
            $this->pc += 2;
            $this->executeInstruction($instruction);

            if ($this->forceStop && !$this->pendingExpectations) {
                break;
            }
        }

        if ($this->pendingExpectations) {
            var_dump($this->pendingExpectations);
            throw new \Exception("Pending expectations", 1);
        }

        $expectedReturn = $this->entry->return;
        $actualReturn = $this->registers[0];
        if ($expectedReturn !== null && $actualReturn !== $expectedReturn) {
            throw new \Exception("Unexpected return value $actualReturn, expecting $expectedReturn", 1);
        }

        // TODO: returns and float returns are mutually exclusive
        if ($this->entry->floatReturn !== null) {
            $expectedFloatReturn = $this->entry->floatReturn;
            $actualFloatReturn = $this->fregisters[0];
            $expectedDecRepresentation = unpack('L', pack('f', $expectedFloatReturn))[1];
            $actualDecRepresentation = unpack('L', pack('f', $actualFloatReturn))[1];

            if ($actualDecRepresentation !== $expectedDecRepresentation) {
                throw new \Exception("Unexpected return value $actualFloatReturn, expecting $expectedFloatReturn", 1);
            }
        }

        echo "Passed\n";
    }

    public function readInstruction(int $address): int
    {
        return $this->memory->readUInt16($address);
    }

    public function executeDelaySlot(): void
    {
        // TODO: refactor duplicated code
        $instruction = $this->readInstruction($this->pc);
        $this->log("; 0x" . dechex($this->pc) . ' ' . str_pad(dechex($instruction), 4, '0', STR_PAD_LEFT) . "   _");
        $this->pc += 2;
        $this->executeInstruction($instruction);
    }

    public function executeInstruction(int $instruction): void
    {
        switch ($instruction) {
            // NOP
            case 0x0009:
                $this->log("NOP\n");
                // Do nothing
                return;

            // RTS
            case 0x000b:
                $this->log("RTS\n");
                $this->executeDelaySlot();
                // TODO: Handle simulated calls
                $this->running = false;
                return;
        }

        switch ($instruction & 0xf000) {
            // MOV.L <REG_M>,@(<disp>,<REG_N>)
            case 0x1000:
                [$n, $m] = getNM($instruction);
                $disp = getImm4($instruction) << 2;
                $this->log("MOV.L       R$m,@($disp,R$n)\n");
                $this->writeUInt32($this->registers[$n], $disp, $this->registers[$m]);
                return;

            // MOV.L @(<disp>,<REG_M>),<REG_N>
            case 0x5000:
                [$n, $m] = getNM($instruction);
                $disp = getImm4($instruction) << 2;
                $this->log("MOV.L        @($disp,R$m),R$n\n");
                $this->registers[$n] = $this->readUInt32($this->registers[$m], $disp);
                return;

            // ADD #imm,Rn
            case 0x7000:
                $n = getN($instruction);
                $imm = getSImm8($instruction);
                $this->log("ADD         #$imm,R$n\n");
                $this->registers[$n] += $imm;
                return;

            // MOV.W @(<disp>,PC),<REG_N>
            case 0x9000:
                $n = getN($instruction);
                $disp = getImm8($instruction) << 1;
                $this->log("MOV.W       @($disp,PC),R$n\n");
                $this->registers[$n] = $this->readUInt16($this->pc + 2, $disp);
                return;

            // MOV #imm,Rn
            case 0xe000:
                $imm = getSImm8($instruction) & 0xffffffff;
                $n = getN($instruction);
                $this->log("MOV         #$imm,R$n\n");
                $this->registers[$n] = $imm;
                return;
        }

        switch ($instruction & 0xf1ff) {
            // TODO
        }

        switch ($instruction & 0xf00f) {
            // MOV.L <REG_M>, @(R0,<REG_N>)
            case 0x0006:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.L       R$m, @(R0,R$n)\n");
                // TODO: Is R0 always the offset?
                $this->writeUInt32($this->registers[$n], $this->registers[0], $this->registers[$m]);
                return;

            // MOV.W @(R0,<REG_M>),<REG_N>
            case 0x000d:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.W       @(R0,R$m),R$n\n");
                $this->registers[$n] = $this->readUInt16($this->registers[0], $this->registers[$m]);
                return;

            // MOV.L @(R0,<REG_M>),<REG_N>
            case 0x000e:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.L       @(R0,R$m),R$n\n");
                $this->registers[$n] = $this->readUInt32($this->registers[0], $this->registers[$m]);
                return;

            // MOV.L Rm,@Rn
            case 0x2002:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.L       R$m,@R$n\n");

                $addr = $this->registers[$n];
                $this->writeUInt32($this->registers[$n], 0, $this->registers[$m]);
                return;

            // MOV.L Rm,@-Rn
            case 0x2006:
                $n = getN($instruction);
                $m = getM($instruction);
                $this->log("MOV.L        R$m,@-R$n\n");

                $addr = $this->registers[$n] - 4;

                $this->memory->writeUInt32($addr, $this->registers[$m]);
                $this->registers[$n] = $addr;
                return;

            // TST Rm,Rn
            case 0x2008:
                [$n, $m] = getNM($instruction);
                $this->log("TST         R$m,R$n\n");

                if (($this->registers[$n] & $this->registers[$m]) !== 0) {
                    $this->srT = 0;
                } else {
                    $this->srT = 1;
                }

                return;

            // OR Rm,Rn
            case 0x200b:
                [$n, $m] = getNM($instruction);
                $this->log("OR          R$m,R$n\n");
                $this->registers[$n] |= $this->registers[$m];
                return;

            // CMP/HS <REG_M>,<REG_N>
            case 0x3002:
                [$n, $m] = getNM($instruction);
                $this->log("CMP/HS      R$m,R$n\n");
                // TODO: Double check signed to unsigned convertion
                if (($this->registers[$n] & 0xffffffff) >= ($this->registers[$m] & 0xffffffff)) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // CMP/GE <REG_M>,<REG_N>
            case 0x3003:
                [$n, $m] = getNM($instruction);
                $this->log("CMP/GE      R$m,R$n\n");
                if ($this->registers[$n] >= $this->registers[$m]) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // CMP/GT <REG_M>,<REG_N>
            case 0x3007:
                [$n, $m] = getNM($instruction);
                $this->log("CMP/GT      R$m,R$n\n");
                if ($this->registers[$n] > $this->registers[$m]) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // ADD Rm,Rn
            case 0x300c:
                [$n, $m] = getNM($instruction);
                $this->log("ADD         R$m,R$n\n");
                $this->registers[$n] += $this->registers[$m];
                return;

            // MOV @Rm,Rn
            case 0x6002:
                [$n, $m] = getNM($instruction);
                $this->log("MOV         @R$m,R$n\n");

                $addr = $this->registers[$m];

                // TODO: Use read proxy?
                $this->registers[$n] = $this->readUInt32($this->registers[$m]);
                return;

            // MOV Rm,Rn
            case 0x6003:
                [$n, $m] = getNM($instruction);
                $this->log("MOV         R$m,R$n\n");
                $this->registers[$n] = $this->registers[$m];
                return;

            // MOV @<REG_M>+,<REG_N>
            case 0x6006:
                [$n, $m] = getNM($instruction);
                $this->log("MOV         @R$m+,R$n\n");
                // TODO: Use read proxy?
                $this->registers[$n] = $this->readUInt32($this->registers[$m]);

                if ($n != $m) {
                    $this->registers[$m] += 4;
                }

                return;

            // NEG <REG_M>,<REG_N>
            case 0x600b:
                [$n, $m] = getNM($instruction);
                $this->log("NEG         R$m,R$n\n");

                // @phpstan-ignore-next-line
                $this->registers[$n] = -$this->registers[$m];
                return;

            // EXTU.B <REG_M>,<REG_N>
            case 0x600c:
                [$n, $m] = getNM($instruction);
                $this->log("EXTU.B      R$m,R$n\n");
                $this->registers[$n] = $this->registers[$m] & 0xff;
                return;

            // FSUB <FREG_M>,<FREG_N>
            case 0xf001:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->log("FSUB        FR$m,FR$n\n");
                    $this->fregisters[$n] -= $this->fregisters[$m];
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                //     double d = getDRn(op) - getDRm(op);
                //     d = fixNaN64(d);
                //     setDRn(op, d);
                // }
                return;

            // FMUL <FREG_M>,<FREG_N>
            case 0xf002:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->log("FMUL        FR$m,FR$n\n");
                    $this->fregisters[$n] *= $this->fregisters[$m];
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                //     double d = getDRn(op) - getDRm(op);
                //     d = fixNaN64(d);
                //     setDRn(op, d);
                // }
                return;

            // FCMP/GT <FREG_M>,<FREG_N>
            case 0xf005:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = getNM($instruction);

                    if ($this->fregisters[$n] > $this->fregisters[$m]) {
                        $this->srT = 1;
                    } else {
                        $this->srT = 0;
                    }
                // }
                // else
                // {
                //     sr.T = getDRn(op) > getDRm(op);
                // }
                return;

            // FMOV.S @(R0, <REG_M>),<FREG_N>
            case 0xf006:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->log("FMOV.S      @(R0, R$m),FR$n\n");

                    // TODO: Use read proxy?
                    $value = $this->readUInt32($this->registers[$m], $this->registers[0]);
                    $this->fregisters[$n] = unpack('f', pack('L', $value))[1];
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@(R0,<REG_N>)
            case 0xf007:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->log("FMOV.S      FR$m,@(R0,R$n)\n");

                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->writeUInt32($this->registers[$n], $this->registers[0], $value);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S @<REG_M>,<FREG_N>
            case 0xf008:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->log("FMOV.S      @R$m,FR$n\n");

                    // TODO: Use read proxy?
                    $value = $this->readUInt32($this->registers[$m]);
                    $this->fregisters[$n] = unpack('f', pack('L', $value))[1];
                // } else {
                    // ...
                // }
                return;

            // FMOV.S @<REG_M>+,<FREG_N>
            case 0xf009:
                $this->log("FMOV.S @<REG_M>+,<FREG_N>\n");
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);

                    // TODO: Use read proxy?
                    $value = $this->readUInt32($this->registers[$m]);
                    $this->fregisters[$n] = unpack('f', pack('L', $value))[1];

                    $this->registers[$m] += 4;
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@-<REG_N>
            case 0xf00b:
                $this->log("FMOV.S <FREG_M>,@-<REG_N>\n");
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);

                    $addr = $this->registers[$n] - 4;

                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->memory->writeUInt32($addr, $value);

                    $this->registers[$n] = $addr;
                // } else {
                    // ...
                // }
                return;

            // FMOV <FREG_M>,<FREG_N>
            case 0xf00c:
                // if (fpscr.SZ == 0)
                // {
                    [$n, $m] = getNM($instruction);
                    $this->log("FMOV        FR$m,FR$n\n");
                    $this->fregisters[$n] = $this->fregisters[$m];
                // }
                // else
                // {
                //     // TODO
                // }
                return;

            // FSTS        FPUL,<FREG_N>
            case 0xf00d:
                $n = getN($instruction);
                $this->log("FSTS        FPUL,FR$n");
                $this->fregisters[$n] = unpack('f', pack('L', $this->fpul))[1];
                return;

            // FMAC <FREG_0>,<FREG_M>,<FREG_N>
            case 0xf00e:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->log("FMAC        FR0,FR$m,FR$n");
                    $this->fregisters[$n] += $this->fregisters[0] * $this->fregisters[$m];
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                //     double d = getDRn(op) - getDRm(op);
                //     d = fixNaN64(d);
                //     setDRn(op, d);
                // }
                return;
        }

        switch ($instruction & 0xff00) {
            // MOV.B @(<disp>, <REG_M>),R0
            case 0x8400:
                $m = getM($instruction);
                $disp = getImm4($instruction);
                $this->log("MOV.B       @($disp, R$m),R0");
                $this->registers[0] = $this->readUInt8($this->registers[$m], $disp);
                return;

            // CMP/EQ #<imm>,R0
            case 0x8800:
                $imm = getSImm8($instruction);
                $this->log("CMP/EQ      #$imm,R0\n");
                if ($this->registers[0] === $imm) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // BT <bdisp8>
            case 0x8900:
                $this->log("BT          <bdisp8>\n");
                if ($this->srT !== 0) {
                    $this->pc = branchTargetS8($instruction, $this->pc);
                }
                return;

            // BF <bdisp8>
            case 0x8b00:
                $this->log("BF          <bdisp8>\n");
                if ($this->srT === 0) {
                    $this->pc = branchTargetS8($instruction, $this->pc);
                }
                return;

            // BT/S        <bdisp8>
            case 0x8d00:
                $newpc = branchTargetS8($instruction, $this->pc);
                if ($this->srT !== 0) {
                    $this->executeDelaySlot();
                    $this->pc = $newpc;
                }
                $this->log("BT/S        H'" . dechex($newpc) . "\n");
                return;

            // BF/S <bdisp8>
            case 0x8f00:
                $this->log("BF/S        <bdisp8>\n");
                if ($this->srT === 0) {
                    $newpc = branchTargetS8($instruction, $this->pc);
                    $this->executeDelaySlot();
                    $this->pc = $newpc;
                }
                return;

            // MOVA @(<disp>,PC),R0
            case 0xc700:
                $this->log("MOVA        @(<disp>,PC),R0\n");
                $this->registers[0] = (($this->pc + 2) & 0xfffffffc) + (getImm8($instruction) << 2);
                return;

            // TST #imm,R0
            case 0xc800:
                $imm = getImm8($instruction);
                $this->log("TST         #$imm,R0\n");
                if (($this->registers[0] & $imm) === 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                return;
        }

        // f0ff
        switch ($instruction & 0xf0ff) {
            // BRAF <REG_N>
            case 0x0023:
                $n = getN($instruction);
                $this->log("BRAF        R$n\n");
                $newpc = $this->registers[$n] + $this->pc + 2;

                //WARN : r[n] can change here
                $this->executeDelaySlot();

                $this->pc = $newpc;
                return;

            // MOVT <REG_N>
            case 0x0029:
                $this->log("MOVT        <REG_N>\n");
                $n = getN($instruction);
                $this->registers[$n] = $this->srT;
                return;

            // SHLL <REG_N>
            case 0x4000:
                $this->log("SHLL        <REG_N>\n");
                $n = getN($instruction);
                $this->srT = $n >> 31;
                $this->registers[$n] <<= 1;
                return;

            // SHLL2  Rn;
            case 0x4008:
                $n = getN($instruction);
                $this->log("SHLL2       R$n\n");
                $this->registers[$n] <<= 2;
                return;

            // JSR
            case 0x400b:
                $n = getN($instruction);
                $this->log("JSR         @R$n\n");

                $newpr = $this->pc + 2;   //return after delayslot
                $newpc = $this->registers[$n];

                $this->executeDelaySlot(); //r[n]/pr can change here

                if ($newpc instanceof Relocation) {
                    $this->assertCall($newpc->name);

                    // TODO: Handle call side effects

                    $this->pc = $newpr;
                    return;
                }

                $this->pr = $newpr;
                $this->pc = $newpc;
                return;

            // CMP/PZ <REG_N>
            case 0x4011:
                $n = getN($instruction);
                $this->log("CMP/PZ      R$n\n");
                if (u2s8($this->registers[$n] & 0xff) >= 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return;

            // SHLL8  Rn;
            case 0x4018:
                $n = getN($instruction);
                $this->log("SHLL8       R$n\n");
                $this->registers[$n] <<= 8;
                return;

            // STS.L PR,@-<REG_N>
            case 0x4022:
                $this->log("STS.L PR,@-<REG_N>\n");
                $n = getN($instruction);
                $address = $this->registers[$n] - 4;
                $this->memory->writeUInt32($address, $this->pr);
                $this->registers[$n] = $address;
                return;

            // LDS.L @<REG_N>+,PR
            case 0x4026:
                $this->log("LDS.L @<REG_N>+,PR\n");
                $n = getN($instruction);
                // TODO: Use read proxy?
                $this->pr = $this->memory->readUInt32($this->registers[$n]);

                $this->registers[$n] += 4;
                return;

            // SHLL16 Rn;
            case 0x4028:
                $n = getN($instruction);
                $this->log("SHLL16      R$n\n");
                $this->registers[$n] <<= 16;
                return;

            // JMP
            case 0x402b:
                $this->log("JMP\n");
                $n = getN($instruction);
                $newpc = $this->registers[$n];
                $this->executeDelaySlot();

                if ($newpc instanceof Relocation) {
                    $this->assertCall($newpc->name);

                    // Program jumped to external symbol
                    $this->running = false;
                    return;
                }

                $this->pc = $newpc;
                return;

            // LDS <REG_M>,FPUL
            case 0x405a:
                $n = getN($instruction);
                $this->log("LDS         R$n,FPUL");
                $this->fpul = $this->registers[$n];

                return;

            // FLDI0
            case 0xf08d:
                $this->log("FLDI0\n");
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }

                $n = getN($instruction);

                $this->fregisters[$n] = 0.0;
                return;
        }

        // f000
        switch ($instruction & 0xf000) {
            // BRA <bdisp12>
            case 0xa000:
                $this->log("BRA         <bdisp12>\n");
                $newpc = branchTargetS12($instruction, $this->pc);
                $this->executeDelaySlot();
                $this->pc = $newpc;
                return;

            // MOV.L @(<disp>,PC),<REG_N>
            case 0xd000:
                $n = getN($instruction);
                $imm = getImm8($instruction);
                $disp = $imm * 4;

                $addr = (($this->pc + 2) & 0xFFFFFFFC);

                $this->log("MOV.L       @($disp,PC),R$n\n");

                $data = $this->readUInt32($addr, $disp);

                // TODO: Should this be done to every read or just disp + PC (Literal Pool)
                if ($relocation = $this->getRelocationAt($addr + $disp)) {
                    // TODO: If rellocation has been initialized in test, set
                    // rellocation address instead.
                    $data = $relocation;
                }

                $this->registers[$n] = $data;
                return;
        }

        throw new \Exception("Unknown instruction " . str_pad(dechex($instruction), 4, '0', STR_PAD_LEFT));
    }

    private function assertCall(string $name): void
    {
        if ($name === '__modls') {
            $this->registers[0] = $this->registers[1] % $this->registers[0];
            return;
        }

        /** @var AbstractExpectation */
        $expectation = array_shift($this->pendingExpectations);

        if (!($expectation instanceof CallExpectation)) {
            throw new \Exception("Unexpected function call to $name at " . dechex($this->pc));
        }

        if ($name !== $expectation->name) {
            throw new \Exception("Unexpected call to $name at " . dechex($this->pc) . ", expecting $expectation->name", 1);
        }

        if ($expectation->parameters) {
            // TODO: Handle other calling convetions?
            $args = 0;
            $floatArgs = 0;
            $stackOffset = 0;
            foreach ($expectation->parameters as $expected) {
                if ($expected instanceof WildcardArgument) {
                    $args++;
                    continue;
                }

                if (is_int($expected)) {
                    $args++;

                    if ($args <= 4) {
                        $register = $args + 4 - 1;
                        $actual = $this->registers[$register];
                        if ($actual !== $expected) {
                            throw new \Exception("Unexpected parameter for $name in r$register. Expected $expected, got $actual", 1);
                        }

                        continue;
                    }

                    $offset = $stackOffset * 4;

                    $address = $this->registers[15] + $offset;
                    $actual = $this->memory->readUInt32($address);

                    if ($actual !== $expected) {
                        throw new \Exception("Unexpected parameter in stack offset $offset ($address). Expected $expected, got $actual", 1);
                    }

                    $stackOffset++;
                } elseif (is_float($expected)) {
                    $floatArgs++;

                    if ($floatArgs <= 4) {
                        $register = $floatArgs + 4 - 1;
                        $actual = $this->fregisters[$register];
                        $actualDecRepresentation = unpack('L', pack('f', $actual))[1];
                        $expectedDecRepresentation = unpack('L', pack('f', $expected))[1];
                        if ($actualDecRepresentation !== $expectedDecRepresentation) {
                            throw new \Exception("Unexpected float parameter for $name in fr$register. Expected $expected, got $actual", 1);
                        }
    
                        continue;
                    }

                    throw new \Exception("Flaot stack parameters are not supported at the moment", 1);
                    // $offset = ($stackOffset - 4) * 4;
                    // $address = $this->registers[15] + $offset;
                    // $actual = $this->memory->readUInt32($address);

                    // if ($actual !== $expected) {
                    //     throw new \Exception("Unexpected parameter in stack offset $offset ($address). Expected $expected, got $actual", 1);
                    // }
                    //
                    // $stackOffset++;
                } else {
                    throw new \Exception("Only integer and floats are supported as parameter expectation", 1);
                }
            }
        }

        if ($expectation->return !== null) {
            $this->registers[0] = $expectation->return;
        }

        $this->log("✅ Expectation fulfilled: Call expectation to " . $name . "\n");
    }

    public function hexdump(): void
    {
        echo "PC: " . dechex($this->pc) . "\n";
        // print_r($this->registers);

        return;

        // TODO: Unhardcode memory size
        for ($i=0; $i < 4096; $i++) {
            if ($i % 16 === 0) {
                echo "\n";
                echo str_pad(dechex($i), 4, '0', STR_PAD_LEFT) . ': ';
            } else if ($i !== 0 && $i % 4 === 0) {
                echo " ";
            }

            if ($this->getRelocationAt($i)) {
                echo "RR RR RR RR ";
                $i += 3;
                continue;
            }

            echo str_pad(dechex($this->memory->readUInt8($i)), 2, '0', STR_PAD_LEFT) . ' ';
        }
    }

    // TODO: Move to Unit
    public function getRelocationAt(int $address): ?Relocation
    {
        foreach ($this->object->unit->sections as $section) {
            foreach ($section->relocations as $relocation) {
                if ($relocation->linkedAddress !== $address) {
                    continue;
                }

                return $relocation;
            }
        }

        return null;
    }

    public function enableDisasm(): void
    {
        $this->disasm = true;
    }

    private function log(mixed $str): void
    {
        if ($this->disasm) {
            echo $str;
        }
    }

    protected function readUInt(int|Relocation $addr, int $offset, int $size): int
    {
        $expectation = reset($this->pendingExpectations);

        if ($addr instanceof Relocation) {
            $relData = $this->memory->readUInt32($addr->linkedAddress);

            // TODO: Handle non offset reads?
            if (!($expectation instanceof SymbolOffsetReadExpectation)) {
                throw new \Exception("Unexpected offset read to " . $addr->name, 1);
            }

            if ($expectation->name !== $addr->name) {
                throw new \Exception("Unexpected offset read to $addr->name. Expecting $expectation->name", 1);
            }

            // TODO: Double check this
            if ($expectation->offset !== $relData + $offset) {
                throw new \Exception("Unexpected offset read " . dechex($relData + $offset) . ". Expecting " . dechex($expectation->offset), 1);
            }

            array_shift($this->pendingExpectations);

            return $expectation->value;
        }

        $displacedAddr = $addr + $offset;

        if ($expectation instanceof ReadExpectation && $expectation->address === $displacedAddr) {
            array_shift($this->pendingExpectations);
            return $expectation->value;
        }

        switch ($size) {
            case 8:
                return $this->memory->readUInt8($displacedAddr);

            case 16:
                return $this->memory->readUInt16($displacedAddr);

            case 32:
                return $this->memory->readUInt32($displacedAddr);
        }

        throw new \Exception("Unsupported read size $size", 1);
    }

    protected function readUInt8(int|Relocation $addr, int $offset = 0): int
    {
        return $this->readUInt($addr, $offset, 8);
    }

    /**
     * @param int|Relocation $addr
    */
    protected function readUInt16(int|Relocation $addr, int $offset = 0): int
    {
        return $this->readUInt($addr, $offset, 16);
    }

    /**
     * @param int|Relocation $addr
    */
    protected function readUInt32(int|Relocation $addr, int $offset = 0): int
    {
        return $this->readUInt($addr, $offset,  32);
    }

    protected function writeUInt32(int|Relocation $addr, int $offset, int $value): void
    {
        $expectation = reset($this->pendingExpectations);

        // TODO: Duplicated in readUInt16, extract to method
        if ($addr instanceof Relocation) {
            $relData = $this->memory->readUInt32($addr->linkedAddress);

            // TODO: Handle non offset writes?
            if (!($expectation instanceof SymbolOffsetWriteExpectation)) {
                throw new \Exception("Unexpected offset write", 1);
            }

            if ($expectation->name !== $addr->name) {
                throw new \Exception("Unexpected offset write to $addr->name. Expecting $expectation->name", 1);
            }

            // TODO: Double check this
            if ($expectation->offset !== $relData + $offset) {
                throw new \Exception("Unexpected offset write " . dechex($relData + $offset) . ". Expecting " . dechex($expectation->offset), 1);
            }

            if ($expectation->value !== $value) {
                throw new \Exception("Unexpected offset write value " . dechex($value) . ". Expecting " . dechex($expectation->value), 1);

            }

            array_shift($this->pendingExpectations);
            return;
        }

        $displacedAddr = $addr + $offset;

        // TODO: Improve code flow
        if ($expectation instanceof WriteExpectation && $expectation->address === $displacedAddr) {
            if ($value !== $expectation->value) {
                throw new \Exception("Unexpected write value $value, expecting $expectation->value", 1);
            }

            array_shift($this->pendingExpectations);
            $this->log("✅ WriteExpectation fulfilled: Wrote " . dechex($value) . " to 0x" . dechex($displacedAddr) . "\n");
        } else {
            throw new \Exception("Unexpected write of 0x" . dechex($value) . " to 0x" . dechex($displacedAddr) . "\n", 1);
        }

        $this->memory->writeUInt32($displacedAddr, $value);
    }
}
