<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\LocalArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExportSymbol;

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

function u2s32(int $u32): int {
    if ($u32 & 0x80000000) {
        return -((~$u32 & 0xffffffff) + 1);
    }

    return $u32;
}

function s2u32(int $s32): int {
    if ($s32 < 0) {
        return (~(-$s32) + 1) & 0xffffffff;
    }

    return $s32;
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

function s8tos32(int $value): int
{
    // Ensure value is 8 bit
    $value &= 0xff;

    // Extend if MSB is set
    if ($value & 0x80) {
        $value |= 0xffffff00;
    }

    return $value;
}

// function hexpad($hex, $len)
// {
//     return str_pad($hex, $len, '0', STR_PAD_LEFT);
// }

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

    /* TODO: Implement SR correctly */
    private int $srT = 0;

    private int $macl = 0;

    private int $fpul = 0;

    private BinaryMemory $memory;

    /** @var AbstractExpectation[] */
    private array $pendingExpectations;

    private bool $disasm = false;

    /** @var Relocation[] */
    private array $unresolvedRelocations = [];

    /**
     * @param AbstractExpectation[] $expectations
     * */ 
    public function __construct(
        private ParsedObject $object,

        array $expectations,

        private Entry $entry,
        
        private bool $forceStop,
        
        /** @var TestRelocation[] */
        private array $testRelocations,
        
        /** @var MemoryInitialization[] */
        private array $initializations,

        private string $linkedCode,
        )
    {
        $this->pendingExpectations = $expectations;

        // Search entry in exports
        /** @var ?ExportSymbol */
        $entrySymbol = $object->unit->findExportedSymbol($entry->symbol);

        if (!$entrySymbol) throw new \Exception("Entry symbol $entry->symbol not found.", 1);

        $this->entryAddress = $entrySymbol->offset;
    }

    public function run(): void
    {
        $this->running = true;
        $this->pc = $this->entryAddress;
        $this->memory = new BinaryMemory(1024 * 1024 * 16);

        // Stack pointer
        $this->registers[15] = 1024 * 1024 * 16 - 4;

        // TODO: Handle other calling convetions
        foreach ($this->entry->parameters as $i => $parameter) {
            if ($i < 4) {
                $this->registers[4 + $i] = $parameter;
                continue;
            }

            $this->registers[15] -= 4;
            $this->memory->writeUInt32($this->registers[15], $parameter);

            // TODO: Handle float parameters
        }

        $this->memory->writeBytes(0, $this->linkedCode);

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

        $unresolvedRelocations = [];
        foreach ($this->object->unit->sections as $section) {
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
                    $unresolvedRelocations[] = $relocation;
                }
            }
        }
        $this->unresolvedRelocations = $unresolvedRelocations;

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
                $this->log("MOV.L       @($disp,R$m),R$n\n");
                $this->setRegister($n, $this->readUInt32($this->registers[$m], $disp));
                return;

            // ADD #imm,Rn
            case 0x7000:
                $n = getN($instruction);
                $imm = getSImm8($instruction);
                $this->log("ADD         #$imm,R$n\n");
                $this->setRegister($n, $this->registers[$n] + $imm);
                return;

            // MOV.W @(<disp>,PC),<REG_N>
            case 0x9000:
                $n = getN($instruction);
                $disp = getImm8($instruction) << 1;
                $this->log("MOV.W       @($disp,PC),R$n\n");
                $this->setRegister($n, $this->readUInt16($this->pc + 2, $disp));
                return;

            // MOV #imm,Rn
            case 0xe000:
                $imm = getSImm8($instruction) & 0xffffffff;
                $n = getN($instruction);
                $this->log("MOV         #$imm,R$n\n");
                $this->setRegister($n, $imm);
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

            // MUL.L <REG_M>,<REG_N>
            case 0x0007:
                [$n, $m] = getNM($instruction);
                $this->log("MUL.L       R$m,R$n\n");
                $result = s2u32(u2s32($this->registers[$n]) * u2s32($this->registers[$m]));
                $this->macl = $result;
                $this->log("[INFO] MACL = 0x" . dechex($result) . "\n");
                return;

            // MOV.B @(R0,<REG_M>),<REG_N>
            case 0x000c:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.B       @(R0, R$m),R$n\n");
                $this->setRegister($n, $this->readUInt8($this->registers[0], $this->registers[$m]));
                return;

            // MOV.W @(R0,<REG_M>),<REG_N>
            case 0x000d:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.W       @(R0,R$m),R$n\n");
                $this->setRegister($n, $this->readUInt16($this->registers[0], $this->registers[$m]));
                return;

            // MOV.L @(R0,<REG_M>),<REG_N>
            case 0x000e:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.L       @(R0,R$m),R$n\n");
                $this->setRegister($n, $this->readUInt32($this->registers[0], $this->registers[$m]));
                return;

            // MOV.B Rm,@Rn
            case 0x2000:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.B       R$m,@R$n\n");

                $addr = $this->registers[$n];
                $this->writeUInt8($this->registers[$n], 0, $this->registers[$m] & 0xff);
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
                $this->log("MOV.L       R$m,@-R$n\n");

                $addr = $this->registers[$n] - 4;

                $this->memory->writeUInt32($addr, $this->registers[$m]);
                $this->setRegister($n, $addr);
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

            // CMP/EQ <REG_M>,<REG_N>
            case 0x3000:
                [$n, $m] = getNM($instruction);
                $this->log("CMP/EQ      R$m,R$n\n");
                if ($this->registers[$n] === $this->registers[$m]) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
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

            // SUB <REG_M>,<REG_N>
            case 0x3008:
                [$n, $m] = getNM($instruction);
                $this->log("SUB         R$m,R$n\n");
                $this->registers[$n] -= $this->registers[$m];
                return;

            // ADD Rm,Rn
            case 0x300c:
                [$n, $m] = getNM($instruction);
                $this->log("ADD         R$m,R$n\n");
                $this->setRegister($n, $this->registers[$n] + $this->registers[$m]);
                return;

            // MOV.B @Rm,Rn
            case 0x6000:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.B       @R$m,R$n\n");

                $this->setRegister($n, s8tos32($this->readUInt8($this->registers[$m])));
                return;

            // MOV @Rm,Rn
            case 0x6002:
                [$n, $m] = getNM($instruction);
                $this->log("MOV         @R$m,R$n\n");

                $this->setRegister($n, $this->readUInt32($this->registers[$m]));
                return;

            // MOV Rm,Rn
            case 0x6003:
                [$n, $m] = getNM($instruction);
                $this->log("MOV         R$m,R$n\n");
                $this->setRegister($n, $this->registers[$m]);
                return;

            // MOV @<REG_M>+,<REG_N>
            case 0x6006:
                [$n, $m] = getNM($instruction);
                $this->log("MOV         @R$m+,R$n\n");
                $this->setRegister($n, $this->readUInt32($this->registers[$m]));

                if ($n != $m) {
                    $this->registers[$m] += 4;
                }

                return;

            // NEG <REG_M>,<REG_N>
            case 0x600b:
                [$n, $m] = getNM($instruction);
                $this->log("NEG         R$m,R$n\n");

                // @phpstan-ignore-next-line
                $this->setRegister($n, -$this->registers[$m]);
                return;

            // EXTU.B <REG_M>,<REG_N>
            case 0x600c:
                [$n, $m] = getNM($instruction);
                $this->log("EXTU.B      R$m,R$n\n");
                $this->setRegister($n, $this->registers[$m] & 0xff);
                return;
            
            // EXTS.B <REG_M>,<REG_N>
            case 0x600e:
                [$n, $m] = getNM($instruction);
                $this->log("EXTS.B      R$m,R$n\n");
                $this->setRegister($n, s8tos32($this->registers[$m]));
                return;

            // FADD <FREG_M>,<FREG_N>
            case 0xf000:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->log("FADD        FR$m,FR$n\n");
                    $this->fregisters[$n] += $this->fregisters[$m];
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

            // FDIV <FREG_M>,<FREG_N>
            case 0xf003:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->log("FDIV        FR$m,FR$n\n");
                    $this->fregisters[$n] /= $this->fregisters[$m];
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
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

            // FMOV.S <FREG_M>,@<REG_N>
            case 0xf00a:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->log("FMOV.S      FR$m,@R$n\n");

                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->writeUInt32($this->registers[$n], 0, $value);
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

                    $this->setRegister($n, $addr);
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
                $this->setRegister(0, $this->readUInt8($this->registers[$m], $disp));
                return;

            // CMP/EQ #<imm>,R0
            case 0x8800:
                $imm = getSImm8($instruction) & 0xffffffff;
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
                $this->log("BT/S        H'" . dechex($newpc) . "\n");
                if ($this->srT !== 0) {
                    $this->executeDelaySlot();
                    $this->pc = $newpc;
                }
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
                $this->setRegister(0, (($this->pc + 2) & 0xfffffffc) + (getImm8($instruction) << 2));
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
            // STS MACL,<REG_N>
            case 0x001a:
                $n = getN($instruction);
                $this->log("STS         MACL,R$n\n");
                $this->setRegister($n, $this->macl);
                return;

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
                $n = getN($instruction);
                $this->log("MOVT        R$n\n");
                $this->setRegister($n, $this->srT);
                return;

            // STS FPUL,<REG_N>
            case 0x005a:
                $n = getN($instruction);
                $this->log("STS         FPUL,R$n\n");
                $this->setRegister($n, $this->fpul);
                return;

            case 0x002a:
                $n = getN($instruction);
                $this->log("STS         PR,R$n\n");
                $this->setRegister($n, $this->pr);
                return;

            // SHLL <REG_N>
            case 0x4000:
                $n = getN($instruction);
                $this->log("SHLL        R$n\n");
                $this->srT = $this->registers[$n] >> 31;
                $this->setRegister($n, $this->registers[$n] << 1);
                return;

            // SHLR <REG_N>
            case 0x4001:
                $n = getN($instruction);
                $this->log("SHLR        R$n\n");
                $this->srT = $this->registers[$n] & 0x1;
                $this->setRegister($n, $this->registers[$n] >> 1);
                return;

            // SHLL2  Rn;
            case 0x4008:
                $n = getN($instruction);
                $this->log("SHLL2       R$n\n");
                $this->setRegister($n, $this->registers[$n] << 2);
                return;

            // JSR
            case 0x400b:
                $n = getN($instruction);
                $this->log("JSR         @R$n\n");

                $newpr = $this->pc + 2;   //return after delayslot
                $newpc = $this->registers[$n];

                $this->executeDelaySlot(); //r[n]/pr can change here

                if ($this->getResolutionAt($newpc)) {
                    $this->assertCall($newpc);

                    // TODO: Handle call side effects
                    $this->pc = $newpr;
                    return;
                }

                // FIXME: Duplicated in JMP
                // Handle dynamic calls
                $expectation = reset($this->pendingExpectations);
                if ($expectation && $expectation instanceof CallExpectation && $expectation->address === $newpc) {
                    $this->assertCall($newpc);
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

            // CMP/PL <REG_N>
            case 0x4015:
                $n = getN($instruction);
                $this->log("CMP/PL      R$n\n");
                
                if (u2s8($this->registers[$n] & 0xff) > 0) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // SHLL8  Rn;
            case 0x4018:
                $n = getN($instruction);
                $this->log("SHLL8       R$n\n");
                $this->setRegister($n, $this->registers[$n] << 8);
                return;

            // SHAR  Rn;
            case 0x4021:
                $n = getN($instruction);
                $this->log("SHAR        R$n\n");
                $this->srT = $this->registers[$n] & 0x1;
                $sign = $this->registers[$n] & 0x80000000;
                $this->setRegister($n, ($this->registers[$n] >> 1) | $sign);
                return;

            // STS.L PR,@-<REG_N>
            case 0x4022:
                $n = getN($instruction);
                $this->log("STS.L PR,@-R$n\n");
                $address = $this->registers[$n] - 4;
                $this->memory->writeUInt32($address, $this->pr);
                $this->setRegister($n, $address);
                return;

            // LDS.L @<REG_N>+,PR
            case 0x4026:
                $n = getN($instruction);
                $this->log("LDS.L @R$n+,PR\n");
                // TODO: Use read proxy?
                $this->pr = $this->memory->readUInt32($this->registers[$n]);

                $this->registers[$n] += 4;
                return;

            // SHLL16 Rn;
            case 0x4028:
                $n = getN($instruction);
                $this->log("SHLL16      R$n\n");
                $this->setRegister($n, $this->registers[$n] << 16);
                return;

            // JMP
            case 0x402b:
                $n = getN($instruction);
                $newpc = $this->registers[$n];
                $this->log("JMP         R$n\n");
                $this->executeDelaySlot();

                if ($this->getResolutionAt($newpc)) {
                    $this->assertCall($newpc);

                    // Program jumped to external symbol
                    $this->running = false;
                    return;
                }

                // Handle dynamic jumps (mostly used in tail calls)
                $expectation = reset($this->pendingExpectations);
                if ($expectation && $expectation instanceof CallExpectation && $expectation->address === $newpc) {
                    $this->assertCall($newpc);

                    // Program jumped to dynamic function.
                    //
                    // For now we assume that the function will never jump back
                    // to the caller.
                    $this->running = false;
                    return;
                }

                $this->log("[INFO] PC = 0x" . dechex($newpc) . "\n");

                $this->pc = $newpc;
                return;

            // LDS <REG_M>,FPUL
            case 0x405a:
                $n = getN($instruction);
                $this->log("LDS         R$n,FPUL\n");
                $this->fpul = $this->registers[$n];

                return;

            // FSTS        FPUL,<FREG_N>
            case 0xf00d:
                $n = getN($instruction);
                $this->log("FSTS        FPUL,FR$n\n");
                $this->fregisters[$n] = unpack('f', pack('L', $this->fpul))[1];
                return;

            // FLOAT       FPUL,<FREG_N>
            case 0xf02d:
                $n = getN($instruction);
                $this->log("FLOAT       FPUL,FR$n\n");
                $this->fregisters[$n] = (float) $this->fpul;
                return;

            // FTRC <FREG_N>,FPUL
            case 0xf03d:
                $n = getN($instruction);
                $this->log("FTRC        FR$n,FPUL\n");
                $this->fpul = (int) $this->fregisters[$n];
                return;

            // FNEG <FREG_N>
            case 0xf04d:
                $n = getN($instruction);
                $this->log("FNEG        FR$n\n");

                // if (fpscr.PR ==0)
                $this->fregisters[$n] = -$this->fregisters[$n];
                // else
                return;

            // FLDI0
            case 0xf08d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }
                    
                $n = getN($instruction);
                $this->log("FLDI0       FR$n\n");

                $this->fregisters[$n] = 0.0;
                return;

            // FLDI1
            case 0xf09d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }
                    
                $n = getN($instruction);
                $this->log("FLDI1       FR$n\n");

                $this->fregisters[$n] = 1.0;
                return;
        }

        // f000
        switch ($instruction & 0xf000) {
            // BRA <bdisp12>
            case 0xa000:
                $newpc = branchTargetS12($instruction, $this->pc);
                $newpcHex = dechex($newpc);
                $this->log("BRA         H'$newpcHex\n");
                $this->executeDelaySlot();

                // Handle dynamic jumps
                // FIXME: Duplicated
                $expectation = reset($this->pendingExpectations);
                if ($expectation && $expectation instanceof CallExpectation && $expectation->address === $newpc) {
                    $this->assertCall($newpc);

                    // Program jumped to dynamic function.
                    //
                    // For now we assume that the function will never jump back
                    // to the caller.
                    $this->running = false;
                    return;
                }

                $this->pc = $newpc;
                return;

            // BSR <bdisp12>
            case 0xb000:
                $newpr = $this->pc + 2;   //return after delayslot
                $newpc = branchTargetS12($instruction, $this->pc);
                $newpcHex = dechex($newpc);
                $this->log("BSR         H'$newpcHex\n");
                $this->executeDelaySlot();

                if ($this->object->unit->findExportedAddress($newpc)) {
                    $this->assertCall($newpc);
                    return;
                }

                $this->pr = $newpr;
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

                $this->setRegister($n, $data);
                return;
        }

        throw new \Exception("Unknown instruction " . str_pad(dechex($instruction), 4, '0', STR_PAD_LEFT));
    }

    private function setRegister(int $n, int|Relocation $value): void
    {
        if ($value instanceof Relocation) {
            throw new \Exception("Trying to write relocation $value->name to R$n");
        }

        $this->registers[$n] = $value;

        if ($this->disasm) {
            $hex = dechex($value);
            $this->log("[INFO] R$n = $value(0x$hex)\n");
        }
    }

    private function getRegister(int $n): int
    {
        $value = $this->registers[$n];

        if ($this->disasm) {
            $value = $this->registers[$n];
            $hex = dechex($value);
            $this->log("Get register r$n with value $hex\n");
        }

        return $value;
    }

    private function assertCall(int $target): void
    {
        $name = null;
        $readableName = "<NO_SYMBOL>";

        if ($export = $this->object->unit->findExportedAddress($target)) {
            $name = $readableName = $export->name;
        } elseif ($resolution = $this->getResolutionAt($target)) {
            $name = $readableName = $resolution->name;
        }

        // FIXME: modls and modlu probrably behave differently
        if ($name === '__modls' || $name === '__modlu') {
            $this->setRegister(0, $this->registers[1] % $this->registers[0]);
            return;
        }

        if ($name === '__divls') {
            $this->setRegister(0, (int) ($this->registers[1] / $this->registers[0]));
            return;
        }

        /** @var AbstractExpectation */
        $expectation = array_shift($this->pendingExpectations);

        if (!($expectation instanceof CallExpectation)) {
            throw new \Exception("Unexpected function call to $readableName at " . dechex($this->pc));
        }

        if ($name !== $expectation->name) {
            throw new \Exception("Unexpected call to $readableName at " . dechex($this->pc) . ", expecting $expectation->name", 1);
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

                if ($expected instanceof LocalArgument) {
                    // FIXME: Why increment here!?
                    $args++;

                    if ($args <= 4) {
                        $register = $args + 4 - 1;
                        $actual = $this->registers[$register];

                        if ($actual < $this->registers[15]) {
                            throw new \Exception("Unexpected local argument for $readableName in r$register. $actual is not in the stack", 1);
                        }

                        continue;
                    }

                    throw new \Exception("Stack arguments stored in stack are not supported at the moment", 1);
                }

                if (is_int($expected)) {
                    $expected &= 0xffffffff;

                    // FIXME: Why increment here!?
                    $args++;

                    if ($args <= 4) {
                        $register = $args + 4 - 1;
                        $actual = $this->registers[$register];
                        $actualHex = dechex($actual);
                        $expectedHex = dechex($expected);
                        if ($actual !== $expected) {
                            throw new \Exception("Unexpected parameter for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)", 1);
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
                            throw new \Exception("Unexpected float parameter for $readableName in fr$register. Expected $expected, got $actual", 1);
                        }
    
                        continue;
                    }

                    throw new \Exception("Float stack parameters are not supported at the moment", 1);
                    // $offset = ($stackOffset - 4) * 4;
                    // $address = $this->registers[15] + $offset;
                    // $actual = $this->memory->readUInt32($address);

                    // if ($actual !== $expected) {
                    //     throw new \Exception("Unexpected parameter in stack offset $offset ($address). Expected $expected, got $actual", 1);
                    // }
                    //
                    // $stackOffset++;
                } else if (is_string($expected)) {
                    $args++;

                    if ($args <= 4) {
                        $register = $args + 4 - 1;
                        $address = $this->registers[$register];

                        $actual = $this->memory->readString($address);
                        if ($actual !== $expected) {
                            $actualHex = bin2hex($actual);
                            $expectedHex = bin2hex($expected);
                            throw new \Exception("Unexpected char* argument for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)", 1);
                        }

                        continue;
                    }

                    throw new \Exception("String literal stack arguments are not supported at the moment", 1);
                }
            }
        }

        // TODO: Temporary hack to modify write during runtime
        if ($expectation->callback) {
            $callback = \Closure::bind($expectation->callback, $this, $this);
            $callback($expectation->parameters);
        }

        if ($expectation->return !== null) {
            $this->setRegister(0, $expectation->return);
        }

        $this->log("✅ Expectation fulfilled: Call expectation to " . $readableName . '(0x'. dechex($target) . ")\n");
    }

    public function hexdump(): void
    {
        echo "PC: " . dechex($this->pc) . "\n";
        // print_r($this->registers);

        return;

        // TODO: Unhardcode memory size
        for ($i=0x600; $i < 0x900; $i++) {
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

        for ($i=0x800000; $i < 0x800000 + 0x40; $i++) {
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
        foreach ($this->unresolvedRelocations as $relocation) {
            if ($relocation->linkedAddress === $address) {
                return $relocation;
            }
        }

        return null;
    }

    public function getResolutionAt(int $address): ?TestRelocation
    {
        foreach ($this->testRelocations as $relocation) {
            if ($relocation->address === $address) {
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

    protected function readUInt(int $addr, int $offset, int $size): int
    {
        $displacedAddr = $addr + $offset;

        $readableAddress = '0x' . dechex($displacedAddr);
        if ($relocation = $this->getResolutionAt($displacedAddr)) {
            $readableAddress = "$relocation->name($readableAddress)";
        }

        $expectation = reset($this->pendingExpectations);

        $value = match ($size) {
            8 => $this->memory->readUInt8($displacedAddr),
            16 => $this->memory->readUInt16($displacedAddr),
            32 => $this->memory->readUInt32($displacedAddr),
            default => throw new \Exception("Unsupported read size $size", 1),
        };

        if ($value instanceof Relocation) {
            throw new \Exception("Trying to read relocation $value->name in $readableAddress");
        }

        $readableValue = $value . ' (0x' . dechex($value) . ')';

        // Handle read expectations
        if ($expectation instanceof ReadExpectation && $expectation->address === $displacedAddr) {
            $readableExpected = $expectation->value . ' (0x' . dechex($expectation->value) . ')';

            if ($size !== $expectation->size) {
                throw new \Exception("Unexpected read size $size from $readableAddress. Expecting size $expectation->size", 1);
            }

            if ($value !== $expectation->value) {
                throw new \Exception("Unexpected read of $readableValue from $readableAddress. Expecting value $readableExpected", 1);
            }

            $this->log("✅ ReadExpectation fulfilled: Read $readableExpected from $readableAddress\n");
            array_shift($this->pendingExpectations);
            return $value;
        }

        // Do not log literal pool reads
        if ($displacedAddr >= 1024 * 1024 * 8) {
            $this->log("[INFO] Allowed read of $readableValue from $readableAddress\n");
        }

        return $value;
    }

    protected function readUInt8(int $addr, int $offset = 0): int
    {
        return $this->readUInt($addr, $offset, 8);
    }

    protected function readUInt16(int $addr, int $offset = 0): int
    {
        return $this->readUInt($addr, $offset, 16);
    }

    protected function readUInt32(int $addr, int $offset = 0): int
    {
        return $this->readUInt($addr, $offset,  32);
    }

    private function writeUInt(int $addr, int $offset, int $value, int $size): void
    {
        $displacedAddr = $addr + $offset;

        $this->validateWriteExpectation($displacedAddr, $value, $size);

        match ($size) {
            8 => $this->memory->writeUInt8($displacedAddr, $value),
            16 => $this->memory->writeUInt16($displacedAddr, $value),
            32 => $this->memory->writeUInt32($displacedAddr, $value),
            default => throw new \Exception("Unsupported write size $size", 1),
        };
    }

    private function validateWriteExpectation(int $address, int $value, int $size): void
    {
        $expectation = reset($this->pendingExpectations);
        $readableAddress = '0x' . dechex($address);
        $readableValue = $value . ' (0x' . dechex($value) . ')';
        
        // Stack writes are allowed
        // TODO: Allow user to define allowed writes
        if (is_int($this->registers[15]) && $address >= $this->registers[15]) {
            $this->log("[INFO] Allowed stack write of $readableValue to $readableAddress\n");

            // TODO: Fix code flow
            // Should stack writes be disallowed?
            if ($expectation instanceof WriteExpectation && $expectation->address === $address) {
                //array_shift($this->pendingExpectations);
                //$this->log("✅ WriteExpectation fulfilled: Wrote $readableValue to $readableAddress\n");
                throw new \Exception("Unimplemented stack write expectation handling", 1);
                
            }
            return;
        }

        if ($relocation = $this->getResolutionAt($address)) {
            $readableAddress = "$relocation->name($readableAddress)";
        }

        if (!($expectation instanceof WriteExpectation || $expectation instanceof StringWriteExpectation)) {
            throw new \Exception("Unexpected write of " . $readableValue . " to " . $readableAddress . "\n", 1);
        }

        $readableExpectedAddress = '0x' . dechex($expectation->address);

        if ($relocation = $this->getResolutionAt($expectation->address)) {
            $readableExpectedAddress = "$relocation->name($readableExpectedAddress)";
        }

        // Handle char* writes
        if (is_string($expectation->value)) {
            if (!($expectation instanceof StringWriteExpectation)) {
                throw new \Exception("Unexpected char* write of $readableValue to $readableAddress, expecting int write of $readableExpectedAddress", 1);
            }

            if ($size !== 32) {
                throw new \Exception("Unexpected non 32bit char* write of $readableValue to $readableAddress", 1);
            }

            $actual = $this->memory->readString($value);
            $readableValue = $actual . ' (' . bin2hex($actual) . ')';
            $readableExpectedValue = $expectation->value . ' (' . bin2hex($expectation->value) . ')';

            if ($expectation->address !== $address) {
                throw new \Exception("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress", 1);
            }
            
            if ($actual !== $expectation->value) {
                throw new \Exception("Unexpected char* write value $readableValue to $readableAddress, expecting $readableExpectedValue", 1);
            }

            $this->log("✅ StringWriteExpectation fulfilled: Wrote $readableValue to $readableAddress\n");
        }
        // Hanlde int writes
        else {
            if (!($expectation instanceof WriteExpectation)) {
                throw new \Exception("Unexpected int write of $readableValue to $readableAddress, expecting char* write of $readableExpectedAddress", 1);
            }

            if ($size !== $expectation->size) {
                throw new \Exception("Unexpected $size bit write of $readableValue to $readableAddress, expecting $expectation->size bit write", 1);
            }

            $readableExpectedValue = $expectation->value . '(0x' . dechex($expectation->value) . ')';
            if ($expectation->address !== $address) {
                throw new \Exception("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress", 1);
            }

            if ($value < 0) {
                throw new \Exception("Unexpected negative write value $readableValue to $readableAddress", 1);
            }

            if ($value !== $expectation->value) {
                throw new \Exception("Unexpected write value $readableValue to $readableAddress, expecting value $readableExpectedValue", 1);
            }

            $this->log("✅ WriteExpectation fulfilled: Wrote $readableValue to $readableAddress\n");
        }

        array_shift($this->pendingExpectations);
    }

    protected function writeUInt8(int $addr, int $offset, int $value): void
    {
        $this->writeUInt($addr, $offset, $value, 8);
    }

    protected function writeUInt16(int $addr, int $offset, int $value): void
    {
        $this->writeUInt($addr, $offset, $value, 16);
    }

    protected function writeUInt32(int $addr, int $offset, int $value): void
    {
        $this->writeUInt($addr, $offset, $value, 32);
    }
}
