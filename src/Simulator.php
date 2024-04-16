<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\LocalArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExportSymbol;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U4;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\UInt;

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

function getImm4(int $instruction): U4
{
    return U4::of($instruction & 0xf);
}

function getImm8(int $instruction): U8
{
    return U8::of($instruction & 0xff);
}

function getSImm8(int $instruction): int
{
    return u2s8($instruction & 0xff);
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

function s16tos32(int $value): int
{
    // Ensure value is 16 bit
    $value &= 0xffff;

    // Extend if MSB is set
    if ($value & 0x8000) {
        $value |= 0xffff0000;
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
    /** @var U32[] */
    private array $registers = [];

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

        for ($i = 0; $i < 16; $i++) {
            $this->registers[$i] = U32::of(0);
        }

        // Stack pointer
        $this->registers[15] = U32::of(1024 * 1024 * 16 - 4);

        // TODO: Handle other calling convetions
        foreach ($this->entry->parameters as $i => $parameter) {
            /** @var int|float $parameter */

            if (!is_int($parameter)) {
                throw new \Exception("Only integer parameters are supported", 1);
            }

            if ($i < 4) {
                $this->registers[4 + $i] = $parameter;
                continue;
            }

            $this->registers[15] = $this->registers[15]->sub(4);

            $this->memory->writeUInt32($this->registers[15]->value, U32::of($parameter));

            // TODO: Handle float parameters
        }

        $this->memory->writeBytes(0, $this->linkedCode);

        foreach ($this->initializations as $initialization) {
            switch ($initialization->size) {
                case U8::BIT_COUNT:
                    $this->memory->writeUInt8($initialization->address, U8::of($initialization->value));
                    break;

                case U16::BIT_COUNT:
                    $this->memory->writeUInt16($initialization->address, U16::of($initialization->value));
                    break;

                case U32::BIT_COUNT:
                    $this->memory->writeUInt32($initialization->address, U32::of($initialization->value));
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
                    U32::of($targetSection->linkedAddress + $lr->target),
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
                    U32::of($targetSection->linkedAddress)->add($offset),
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
                            U32::of($userResolution->address + $relocation->offset)
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
            $this->log("; 0x" . dechex($this->pc) . ' ' . $instruction->hex() . "    ");
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

    public function readInstruction(int $address): U16
    {
        return $this->memory->readUInt16($address);
    }

    public function executeDelaySlot(): void
    {
        // TODO: refactor duplicated code
        $instruction = $this->readInstruction($this->pc);
        $this->log("; 0x" . dechex($this->pc) . ' ' . $instruction->hex() . "   _");
        $this->pc += 2;
        $this->executeInstruction($instruction);
    }

    public function executeInstruction(U16 $instruction): void
    {
        // TODO: Use U16 value directly?
        $instruction = $instruction->value;

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
                $this->running = false;
                return;
        }

        switch ($instruction & 0xf000) {
            // MOV.L <REG_M>,@(<disp>,<REG_N>)
            case 0x1000:
                [$n, $m] = getNM($instruction);
                // TODO: Extract to Instruction Value Object
                $disp = getImm4($instruction)->u32()->shiftLeft(2);
                $this->log("MOV.L       R$m,@($disp,R$n)\n");
                $this->writeUInt32($this->registers[$n]->value, $disp->value, $this->registers[$m]);
                return;

            // MOV.L @(<disp>,<REG_M>),<REG_N>
            case 0x5000:
                [$n, $m] = getNM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft(2);
                $this->log("MOV.L       @($disp,R$m),R$n\n");
                $this->setRegister($n, $this->readUInt32($this->registers[$m]->value, $disp->value));
                return;

            // ADD #imm,Rn
            case 0x7000:
                $n = getN($instruction);
                $imm = getImm8($instruction);
                $this->log("ADD         #$imm,R$n\n");

                // TODO: Use SInt value object
                $this->setRegister($n, $this->registers[$n]->add($imm->extend32(), allowOverflow: true));
                return;

            // MOV.W @(<disp>,PC),<REG_N>
            case 0x9000:
                $n = getN($instruction);
                $disp = getImm8($instruction)->u32()->shiftLeft(1);
                $this->log("MOV.W       @($disp,PC),R$n\n");
                $this->setRegister($n, $this->readUInt16($this->pc + 2, $disp->value)->extend32());
                return;

            // MOV #imm,Rn
            case 0xe000:
                $imm = getImm8($instruction);
                $n = getN($instruction);
                $this->log("MOV         #$imm,R$n\n");
                $this->setRegister($n, $imm->extend32());
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
                $this->writeUInt32($this->registers[$n]->value, $this->registers[0]->value, $this->registers[$m]);
                return;

            // MUL.L <REG_M>,<REG_N>
            case 0x0007:
                [$n, $m] = getNM($instruction);
                $this->log("MUL.L       R$m,R$n\n");
                $result = $this->registers[$n]->mul($this->registers[$m]);
                $this->macl = $result->value;
                $this->log("[INFO] MACL = {$result->readable()}\n");
                return;

            // MOV.B @(R0,<REG_M>),<REG_N>
            case 0x000c:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.B       @(R0, R$m),R$n\n");
                $value = $this->readUInt8($this->registers[0]->value, $this->registers[$m]->value);
                $this->setRegister($n, $value->extend32());
                return;

            // MOV.W @(R0,<REG_M>),<REG_N>
            case 0x000d:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.W       @(R0,R$m),R$n\n");
                $value = $this->readUInt16($this->registers[0]->value, $this->registers[$m]->value);
                $this->setRegister($n, $value->extend32());
                return;

            // MOV.L @(R0,<REG_M>),<REG_N>
            case 0x000e:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.L       @(R0,R$m),R$n\n");
                $this->setRegister($n, $this->readUInt32($this->registers[0]->value, $this->registers[$m]->value));
                return;

            // MOV.B Rm,@Rn
            case 0x2000:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.B       R$m,@R$n\n");

                $addr = $this->registers[$n];
                $this->writeUInt8($this->registers[$n]->value, 0, $this->registers[$m]->trunc8());
                return;

            // MOV.L Rm,@Rn
            case 0x2002:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.L       R$m,@R$n\n");

                $addr = $this->registers[$n];
                $this->writeUInt32($this->registers[$n]->value, 0, $this->registers[$m]);
                return;

            // MOV.L Rm,@-Rn
            case 0x2006:
                $n = getN($instruction);
                $m = getM($instruction);
                $this->log("MOV.L       R$m,@-R$n\n");

                $addr = $this->registers[$n]->value - 4;

                $this->memory->writeUInt32($addr, $this->registers[$m]);
                $this->setRegister($n, U32::of($addr));
                return;

            // TST Rm,Rn
            case 0x2008:
                [$n, $m] = getNM($instruction);
                $this->log("TST         R$m,R$n\n");

                if ($this->registers[$n]->band($this->registers[$m])->value !== 0) {
                    $this->srT = 0;
                } else {
                    $this->srT = 1;
                }

                return;

            // AND <REG_M>,<REG_N>
            case 0x2009:
                [$n, $m] = getNM($instruction);
                $this->log("AND         R$m,R$n\n");
                $this->setRegister($n, $this->registers[$n]->band($this->registers[$m]));
                return;

            // OR Rm,Rn
            case 0x200b:
                [$n, $m] = getNM($instruction);
                $this->log("OR          R$m,R$n\n");
                $this->setRegister($n, $this->registers[$n]->bor($this->registers[$m]));
                return;

            // CMP/EQ <REG_M>,<REG_N>
            case 0x3000:
                [$n, $m] = getNM($instruction);
                $this->log("CMP/EQ      R$m,R$n\n");
                if ($this->registers[$n]->equals($this->registers[$m])) {
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
                if ($this->registers[$n]->greaterThanOrEqual($this->registers[$m])) {
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
                if ($this->registers[$n]->greaterThan($this->registers[$m])) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // SUB <REG_M>,<REG_N>
            case 0x3008:
                [$n, $m] = getNM($instruction);
                $this->log("SUB         R$m,R$n\n");
                $result = $this->registers[$n]->sub($this->registers[$m]);
                $this->setRegister($n, $result);
                return;

            // ADD Rm,Rn
            case 0x300c:
                [$n, $m] = getNM($instruction);
                $this->log("ADD         R$m,R$n\n");

                // TODO: Use SInt value object
                $result = $this->registers[$n]->add($this->registers[$m], allowOverflow: true);
                $this->setRegister($n, $result);
                return;

            // MOV.B @Rm,Rn
            case 0x6000:
                [$n, $m] = getNM($instruction);
                $this->log("MOV.B       @R$m,R$n\n");

                $this->setRegister($n, $this->readUInt8($this->registers[$m]->value)->extend32());
                return;

            // MOV @Rm,Rn
            case 0x6002:
                [$n, $m] = getNM($instruction);
                $this->log("MOV         @R$m,R$n\n");

                $this->setRegister($n, $this->readUInt32($this->registers[$m]->value));
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
                $this->setRegister($n, $this->readUInt32($this->registers[$m]->value));

                if ($n != $m) {
                    $this->registers[$m] = $this->registers[$m]->add(4);
                }

                return;

            // NEG <REG_M>,<REG_N>
            case 0x600b:
                [$n, $m] = getNM($instruction);
                $this->log("NEG         R$m,R$n\n");

                $this->setRegister($n, $this->registers[$m]->invert());
                return;

            // EXTU.B <REG_M>,<REG_N>
            case 0x600c:
                [$n, $m] = getNM($instruction);
                $this->log("EXTU.B      R$m,R$n\n");
                $this->setRegister($n, $this->registers[$m]->trunc8()->u32());
                return;

            // EXTS.B <REG_M>,<REG_N>
            case 0x600e:
                [$n, $m] = getNM($instruction);
                $this->log("EXTS.B      R$m,R$n\n");
                $this->setRegister($n, $this->registers[$m]->trunc8()->extend32());
                return;

            // FADD <FREG_M>,<FREG_N>
            case 0xf000:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->log("FADD        FR$m,FR$n\n");
                    $this->setFloatRegister($n, $this->fregisters[$n] + $this->fregisters[$m]);
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
                    $this->setFloatRegister($n, $this->fregisters[$n] - $this->fregisters[$m]);
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
                    $this->setFloatRegister($n, $this->fregisters[$n] * $this->fregisters[$m]);
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
                    $this->setFloatRegister($n, $this->fregisters[$n] / $this->fregisters[$m]);
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
                    $this->log("FCMP/GT     FR$m,FR$n\n");

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

                    $value = $this->readUInt32($this->registers[$m]->value, $this->registers[0]->value)->value;
                    $this->setFloatRegister($n, unpack('f', pack('L', $value))[1]);
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
                    $this->writeUInt32($this->registers[$n]->value, $this->registers[0]->value, U32::of($value));
                // } else {
                    // ...
                // }
                return;

            // FMOV.S @<REG_M>,<FREG_N>
            case 0xf008:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->log("FMOV.S      @R$m,FR$n\n");

                    $value = $this->readUInt32($this->registers[$m]->value)->value;
                    $this->setFloatRegister($n, unpack('f', pack('L', $value))[1]);
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
                    $value = $this->readUInt32($this->registers[$m]->value)->value;
                    $this->setFloatRegister($n, unpack('f', pack('L', $value))[1]);

                    $this->registers[$m] = $this->registers[$m]->add(4);
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
                    $this->writeUInt32($this->registers[$n]->value, 0, $value);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@-<REG_N>
            case 0xf00b:
                $this->log("FMOV.S <FREG_M>,@-<REG_N>\n");
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);

                    $addr = $this->registers[$n]->sub(4);

                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->memory->writeUInt32($addr->value, U32::of($value));

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
                    $this->setFloatRegister($n, $this->fregisters[$m]);
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
                    $this->log("FMAC        FR0,FR$m,FR$n\n");
                    $this->setFloatRegister($n, $this->fregisters[$n] + $this->fregisters[0] * $this->fregisters[$m]);
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
                $this->setRegister(0, $this->readUInt8($this->registers[$m]->value, $disp->value)->extend32());
                return;

            // CMP/EQ #<imm>,R0
            case 0x8800:
                $imm = getSImm8($instruction) & 0xffffffff;
                $this->log("CMP/EQ      #$imm,R0\n");
                if ($this->registers[0]->equals($imm)) {
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
                /* TODO: Check other for u32 after shift */
                $this->setRegister(
                    0,
                    U32::of(($this->pc + 2) & 0xfffffffc)
                        ->add(getImm8($instruction)
                            ->u32()
                            ->shiftLeft(2)
                            ->value
                        )
                );
                return;

            // TST #imm,R0
            case 0xc800:
                $imm = getImm8($instruction)->u32();
                $this->log("TST         #$imm,R0\n");
                if ($this->registers[0]->band($imm)->equals(0)) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                return;

            // AND #imm,R0
            case 0xc900:
                $imm = getImm8($instruction)->u32();
                $this->log("AND         #$imm,R0\n");
                $this->setRegister(0, $this->registers[0]->band($imm));
                return;
        }

        // f0ff
        switch ($instruction & 0xf0ff) {
            // STS MACL,<REG_N>
            case 0x001a:
                $n = getN($instruction);
                $this->log("STS         MACL,R$n\n");
                $this->setRegister($n, U32::of($this->macl));
                return;

            // BRAF <REG_N>
            case 0x0023:
                $n = getN($instruction);
                $this->log("BRAF        R$n\n");
                $newpc = $this->registers[$n]->value + $this->pc + 2;

                //WARN : r[n] can change here
                $this->executeDelaySlot();

                $this->pc = $newpc;
                return;

            // MOVT <REG_N>
            case 0x0029:
                $n = getN($instruction);
                $this->log("MOVT        R$n\n");
                $this->setRegister($n, U32::of($this->srT));
                return;

            // STS FPUL,<REG_N>
            case 0x005a:
                $n = getN($instruction);
                $this->log("STS         FPUL,R$n\n");
                $this->setRegister($n, U32::of($this->fpul));
                return;

            case 0x002a:
                $n = getN($instruction);
                $this->log("STS         PR,R$n\n");
                $this->setRegister($n, U32::of($this->pr));
                return;

            // SHLL <REG_N>
            case 0x4000:
                $n = getN($instruction);
                $this->log("SHLL        R$n\n");
                $this->srT = $this->registers[$n]->shiftRight(31)->value;
                $this->setRegister($n, $this->registers[$n]->shiftLeft(1));
                return;

            // SHLR <REG_N>
            case 0x4001:
                $n = getN($instruction);
                $this->log("SHLR        R$n\n");
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $this->setRegister($n, $this->registers[$n]->shiftRight(1));
                return;

            // SHLL2  Rn;
            case 0x4008:
                $n = getN($instruction);
                $this->log("SHLL2       R$n\n");
                $this->setRegister($n, $this->registers[$n]->shiftLeft(2));
                return;

            // JSR
            case 0x400b:
                $n = getN($instruction);
                $this->log("JSR         @R$n\n");

                $newpr = $this->pc + 2;   //return after delayslot
                $newpc = $this->registers[$n]->value;

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
                if ($this->registers[$n]->signedValue() >= 0) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // CMP/PL <REG_N>
            case 0x4015:
                $n = getN($instruction);
                $this->log("CMP/PL      R$n\n");

                if ($this->registers[$n]->signedValue() > 0) {
                    $this->srT = 1;
                    return;
                }

                $this->srT = 0;
                return;

            // SHLL8  Rn;
            case 0x4018:
                $n = getN($instruction);
                $this->log("SHLL8       R$n\n");
                $this->setRegister($n, $this->registers[$n]->shiftLeft(8));
                return;

            // SHAR  Rn;
            case 0x4021:
                $n = getN($instruction);
                $this->log("SHAR        R$n\n");
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $sign = $this->registers[$n]->band(0x80000000);
                $this->setRegister($n, $this->registers[$n]->shiftRight(1)->bor($sign));
                return;

            // STS.L PR,@-<REG_N>
            case 0x4022:
                $n = getN($instruction);
                $this->log("STS.L PR,@-R$n\n");
                $address = $this->registers[$n]->sub(4);
                $this->memory->writeUInt32($address->value, U32::of($this->pr));
                $this->setRegister($n, $address);
                return;

            // LDS.L @<REG_N>+,PR
            case 0x4026:
                $n = getN($instruction);
                $this->log("LDS.L @R$n+,PR\n");
                // TODO: Use read proxy?
                $this->pr = $this->memory->readUInt32($this->registers[$n]->value)->value;

                $this->registers[$n] = $this->registers[$n]->add(4);
                return;

            // SHLL16 Rn;
            case 0x4028:
                $n = getN($instruction);
                $this->log("SHLL16      R$n\n");
                $this->setRegister($n, $this->registers[$n]->shiftLeft(16));
                return;

            // JMP
            case 0x402b:
                $n = getN($instruction);
                $newpc = $this->registers[$n]->value;
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
                $this->fpul = $this->registers[$n]->value;

                return;

            // FSTS        FPUL,<FREG_N>
            case 0xf00d:
                $n = getN($instruction);
                $this->log("FSTS        FPUL,FR$n\n");
                $this->setFloatRegister($n, unpack('f', pack('L', $this->fpul))[1]);
                return;

            // FLOAT       FPUL,<FREG_N>
            case 0xf02d:
                $n = getN($instruction);
                $this->log("FLOAT       FPUL,FR$n\n");
                $this->setFloatRegister($n, (float) $this->fpul);
                return;

            // FTRC <FREG_N>,FPUL
            case 0xf03d:
                $n = getN($instruction);
                $this->log("FTRC        FR$n,FPUL\n");
                $this->fpul = ((int) $this->fregisters[$n]) & 0xffffffff;
                return;

            // FNEG <FREG_N>
            case 0xf04d:
                $n = getN($instruction);
                $this->log("FNEG        FR$n\n");

                // if (fpscr.PR ==0)
                $this->setFloatRegister($n, -$this->fregisters[$n]);
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

                $this->setFloatRegister($n, 0.0);
                return;

            // FLDI1
            case 0xf09d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }
                    
                $n = getN($instruction);
                $this->log("FLDI1       FR$n\n");

                $this->setFloatRegister($n, 1.0);
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
                $disp = getImm8($instruction)->u32()->mul(4);

                $addr = (($this->pc + 2) & 0xFFFFFFFC);

                $this->log("MOV.L       @($disp,PC),R$n\n");

                $data = $this->readUInt32($addr, $disp->value);

                // TODO: Should this be done to every read or just disp + PC (Literal Pool)
                if ($relocation = $this->getRelocationAt($addr + $disp->value)) {
                    // TODO: If rellocation has been initialized in test, set
                    // rellocation address instead.
                    $data = $relocation;
                }

                $this->setRegister($n, $data);
                return;
        }

        throw new \Exception("Unknown instruction " . str_pad(dechex($instruction), 4, '0', STR_PAD_LEFT));
    }

    private function setRegister(int $n, U32|Relocation $value): void
    {
        if ($value instanceof Relocation) {
            throw new \Exception("Trying to write relocation $value->name to R$n");
        }

        $this->registers[$n] = $value;

        if ($this->disasm) {
            $this->log("[INFO] R$n = {$value->readable()}\n");
        }
    }

    // private function getRegister(int $n): U32
    // {
    //     $value = $this->registers[$n];

    //     if ($this->disasm) {
    //         $value = $this->registers[$n];
    //         $this->log("Get register r$n with value {$value->readable()}\n");
    //     }

    //     return $value;
    // }

    private function setFloatRegister(int $n, float $value): void
    {
        $this->fregisters[$n] = $value;

        if ($this->disasm) {
            $this->log("[INFO] FR$n = $value\n");
        }
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
            $this->setRegister(0, $this->registers[1]->mod($this->registers[0]));
            return;
        }

        if ($name === '__divls') {
            $this->setRegister(0, $this->registers[1]->div($this->registers[0]));
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
                        $actualHex = dechex($actual->value);
                        $expectedHex = dechex($expected);
                        if (!$actual->equals($expected)) {
                            throw new \Exception("Unexpected parameter for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)", 1);
                        }

                        continue;
                    }

                    $offset = $stackOffset * 4;

                    $address = $this->registers[15]->value + $offset;
                    $actual = $this->memory->readUInt32($address);

                    if (!$actual->equals($expected)) {
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

                        $actual = $this->memory->readString($address->value);
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
            $this->setRegister(0, U32::of($expectation->return));
        }

        $this->log("✅ Expectation fulfilled: Call expectation to " . $readableName . '(0x'. dechex($target) . ")\n");
    }

    public function hexdump(): void
    {
        echo "PC: " . dechex($this->pc) . "\n";
        // print_r($this->registers);

        //return;

        // TODO: Unhardcode memory size
        for ($i=0x0; $i < 0x400; $i++) {
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

            echo str_pad(dechex($this->memory->readUInt8($i)->value), 2, '0', STR_PAD_LEFT) . ' ';
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

            echo str_pad(dechex($this->memory->readUInt8($i)->value), 2, '0', STR_PAD_LEFT) . ' ';
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

    protected function readUInt(int $addr, int $offset, int $size): U8|U16|U32
    {
        $displacedAddr = $addr + $offset;

        $readableAddress = '0x' . dechex($displacedAddr);
        if ($relocation = $this->getResolutionAt($displacedAddr)) {
            $readableAddress = "$relocation->name($readableAddress)";
        }

        $expectation = reset($this->pendingExpectations);

        $value = match ($size) {
            U8::BIT_COUNT => $this->memory->readUInt8($displacedAddr),
            U16::BIT_COUNT => $this->memory->readUInt16($displacedAddr),
            U32::BIT_COUNT => $this->memory->readUInt32($displacedAddr),
            default => throw new \Exception("Unsupported read size $size", 1),
        };

        // if ($value instanceof Relocation) {
        //     throw new \Exception("Trying to read relocation $value->name in $readableAddress");
        // }

        $readableValue = $value . ' (0x' . dechex($value->value) . ')';

        // Handle read expectations
        if ($expectation instanceof ReadExpectation && $expectation->address === $displacedAddr) {
            $readableExpected = $expectation->value . ' (0x' . dechex($expectation->value) . ')';

            if ($size !== $expectation->size) {
                throw new \Exception("Unexpected read size $size from $readableAddress. Expecting size $expectation->size", 1);
            }

            if (!$value->equals($expectation->value)) {
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

    protected function readUInt8(int $addr, int $offset = 0): U8
    {
        return $this->readUInt($addr, $offset, U8::BIT_COUNT);
    }

    protected function readUInt16(int $addr, int $offset = 0): U16
    {
        return $this->readUInt($addr, $offset, U16::BIT_COUNT);
    }

    protected function readUInt32(int $addr, int $offset = 0): U32
    {
        return $this->readUInt($addr, $offset, U32::BIT_COUNT);
    }

    private function writeUInt(int $addr, int $offset, UInt $value): void
    {
        $displacedAddr = $addr + $offset;

        $this->validateWriteExpectation($displacedAddr, $value);

        match (get_class($value)) {
            U8::class => $this->memory->writeUInt8($displacedAddr, $value),
            U16::class => $this->memory->writeUInt16($displacedAddr, $value),
            U32::class => $this->memory->writeUInt32($displacedAddr, $value),
            default => throw new \Exception("Unsupported write size " . $value::BIT_COUNT, 1),
        };
    }

    private function validateWriteExpectation(int $address, UInt $value): void
    {
        $expectation = reset($this->pendingExpectations);
        $readableAddress = '0x' . dechex($address);
        $readableValue = $value->readable();

        // Stack writes are allowed
        // TODO: Allow user to define allowed writes
        if ($address >= $this->registers[15]->value) {
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

            if ($value::BIT_COUNT !== 32) {
                throw new \Exception("Unexpected non 32bit char* write of $readableValue to $readableAddress", 1);
            }

            $actual = $this->memory->readString($value->value);
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

            if ($value::BIT_COUNT !== $expectation->size) {
                throw new \Exception("Unexpected " . $value::BIT_COUNT . " bit write of $readableValue to $readableAddress, expecting $expectation->size bit write", 1);
            }

            $readableExpectedValue = $expectation->value . '(0x' . dechex($expectation->value) . ')';
            if ($expectation->address !== $address) {
                throw new \Exception("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress", 1);
            }

            if ($value->lessThan(0)) {
                throw new \Exception("Unexpected negative write value $readableValue to $readableAddress", 1);
            }

            if (!$value->equals($expectation->value)) {
                throw new \Exception("Unexpected write value $readableValue to $readableAddress, expecting value $readableExpectedValue", 1);
            }

            $this->log("✅ WriteExpectation fulfilled: Wrote $readableValue to $readableAddress\n");
        }

        array_shift($this->pendingExpectations);
    }

    protected function writeUInt8(int $addr, int $offset, U8 $value): void
    {
        $this->writeUInt($addr, $offset, $value);
    }

    protected function writeUInt16(int $addr, int $offset, U16 $value): void
    {
        $this->writeUInt($addr, $offset, $value);
    }

    protected function writeUInt32(int $addr, int $offset, U32 $value): void
    {
        $this->writeUInt($addr, $offset, $value);
    }
}
