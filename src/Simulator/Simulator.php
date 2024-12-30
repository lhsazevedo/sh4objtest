<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator;

use Closure;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation;
use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U4;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\UInt;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\AbstractOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\BranchOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\ControlFlowOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\GenericOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\ReadOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\WriteOperation;

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
    private int $pc;

    private ?int $delayedPc = null;

    private bool $inDelaySlot = false;

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

    private int $disasmPc;

    private Closure $disasmCallback;
    private Closure $addLogCallback;

    public function __construct(
        private BinaryMemory $memory,
    )
    {
        // Default state
        $this->pc = 0;
        for ($i = 0; $i < 16; $i++) {
            $this->registers[$i] = U32::of(0);
        }

        // Stack pointer
        $this->registers[15] = U32::of(1024 * 1024 * 16 - 4);
    }

    public function step(): AbstractOperation
    {
        $code = $this->readInstruction($this->pc);

        $this->disasmPc = $this->pc;
        $this->pc += 2;

        if ($this->delayedPc !== null) {
            $this->inDelaySlot = true;
            $this->pc = $this->delayedPc;
            $this->delayedPc = null;
        }

        $instruction = $this->executeInstruction($code);

        $this->inDelaySlot = false;

        return $instruction;
    }

    public function readInstruction(int $address): U16
    {
        return $this->memory->readUInt16($address);
    }

    public function executeInstruction(U16 $instruction): AbstractOperation
    {
        // TODO: Use U16 value directly?
        $instruction = $instruction->value;

        switch ($instruction) {
            // NOP
            case 0x0009:
                $this->emitDisasm("NOP");
                // Do nothing
                return new GenericOperation($instruction, $instruction);

            // RTS
            case 0x000b:
                $this->emitDisasm("RTS");
                $this->delayedPc = $this->pr;
                return new BranchOperation($instruction, $instruction, U32::of($this->pr));
        }

        switch ($opcode = $instruction & 0xf000) {
            // MOV.L <REG_M>,@(<disp>,<REG_N>)
            case 0x1000:
                [$n, $m] = getNM($instruction);
                // TODO: Extract to Instruction Value Object
                $disp = getImm4($instruction)->u32()->shiftLeft(2)->value;
                $this->emitDisasm("MOV.L", ["R$m", "@($disp,R$n)"]);
                $this->writeUInt32($this->registers[$n]->value, $disp, $this->registers[$m]);
                return new WriteOperation($instruction, $opcode, $this->registers[$n]->add($disp), $this->registers[$m]);

            // MOV.L  @(<disp>,<REG_M>),<REG_N>
            case 0x5000:
                [$n, $m] = getNM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft(2)->value;
                $this->emitDisasm("MOV.L", ["@($disp,R$m)","R$n"]);
                $source = $this->registers[$m]->add($disp);
                $value = $this->readUInt32($this->registers[$m]->value, $disp);
                $this->writeRegister($n, $this->readUInt32($this->registers[$m]->value, $disp));
                return new ReadOperation($instruction, $opcode, $source, $value);

            // ADD #imm,Rn
            case 0x7000:
                $n = getN($instruction);
                $imm = getImm8($instruction);
                $this->emitDisasm("ADD", ["#{$imm->hitachiSignedHex()}","R$n"]);

                // TODO: Use SInt value object
                $this->writeRegister($n, $this->registers[$n]->add($imm->extend32(), allowOverflow: true));
                return new GenericOperation($instruction, $opcode);

            // MOV.W @(<disp>,PC),<REG_N>
            case 0x9000:
                $n = getN($instruction);
                $disp = getImm8($instruction)->u32()->shiftLeft()->value;
                $this->emitDisasm("MOV.W", ["@($disp,PC)","R$n"]);
                $value = $this->readUInt16($this->pc + 2, $disp)->extend32();
                $this->writeRegister($n, $this->readUInt16($this->pc + 2, $disp)->extend32());
                return new ReadOperation($instruction, $opcode, U32::of($this->pc + 2 + $disp), $value);

            // MOV #imm,Rn
            case 0xe000:
                $imm = getImm8($instruction);
                $n = getN($instruction);
                $this->emitDisasm("MOV", ["#{$imm->hitachiSignedHex()}","R$n"]);
                $this->writeRegister($n, $imm->extend32());
                return new GenericOperation($instruction, $opcode);
        }

        switch ($opcode = $instruction & 0xf1ff) {
            // TODO
        }

        switch ($opcode = $instruction & 0xf00f) {
            // MOV.W <REG_M>, @(R0, <REG_N>)
            case 0x0005:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.W", ["R$m", "@(R0,R$n)"]);
                $this->writeUInt16($this->registers[$n]->value, $this->registers[0]->value, $this->registers[$m]->trunc16());
                return new WriteOperation($instruction, $opcode, $this->registers[$n]->add($this->registers[0]), $this->registers[$m]->trunc16());

            // MOV.L <REG_M>, @(R0,<REG_N>)
            case 0x0006:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.L", ["R$m", "@(R0,R$n)"]);
                // TODO: Is R0 always the offset?
                // TODO2: Why this matters?
                $this->writeUInt32($this->registers[$n]->value, $this->registers[0]->value, $this->registers[$m]);
                return new WriteOperation($instruction, $opcode, $this->registers[$n]->add($this->registers[0]), $this->registers[$m]);

            // MUL.L <REG_M>,<REG_N>
            case 0x0007:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MUL.L", ["R$m","R$n"]);
                $result = $this->registers[$n]->mul($this->registers[$m]);
                $this->macl = $result->value;
                $this->emitAddLog("MACL={$result->readable()}");
                return new GenericOperation($instruction, $opcode);

            // MOV.B @(R0,<REG_M>),<REG_N>
            case 0x000c:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.B", ["@(R0, R$m)","R$n"]);
                $source = $this->registers[0]->add(($this->registers[$m]));
                $value = $this->readUInt8($this->registers[0]->value, $this->registers[$m]->value)->extend32();
                $this->writeRegister($n, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV.W @(R0,<REG_M>),<REG_N>
            case 0x000d:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.W", ["@(R0,R$m)","R$n"]);
                $source = $this->registers[0]->add($this->registers[$m]);
                $value = $this->readUInt16($this->registers[0]->value, $this->registers[$m]->value)->extend32();
                $this->writeRegister($n, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV.L @(R0,<REG_M>),<REG_N>
            case 0x000e:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.L", ["@(R0,R$m)","R$n"]);
                $source = $this->registers[0]->add($this->registers[$m]);
                $value = $this->readUInt32($this->registers[0]->value, $this->registers[$m]->value);
                $this->writeRegister($n, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV.B Rm,@Rn
            case 0x2000:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.B", ["R$m", "@R$n"]);
                $addr = $this->registers[$n];
                $this->writeUInt8($this->registers[$n]->value, 0, $this->registers[$m]->trunc8());
                return new WriteOperation($instruction, $opcode, $this->registers[$n], $this->registers[$m]->trunc8());

            // MOV.W Rm,@Rn
            case 0x2001:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.W", ["R$m", "@R$n"]);
                $addr = $this->registers[$n];
                $this->writeUInt16($this->registers[$n]->value, 0, $this->registers[$m]->trunc16());
                return new WriteOperation($instruction, $opcode, $this->registers[$n], $this->registers[$m]->trunc16());

            // MOV.L Rm,@Rn
            case 0x2002:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.L", ["R$m", "@R$n"]);
                $addr = $this->registers[$n];
                $this->writeUInt32($this->registers[$n]->value, 0, $this->registers[$m]);
                return new WriteOperation($instruction, $opcode, $this->registers[$n], $this->registers[$m]);

            // MOV.L Rm,@-Rn
            case 0x2006:
                $n = getN($instruction);
                $m = getM($instruction);
                $this->emitDisasm("MOV.L", ["R$m", "@-R$n"]);   
                $addr = $this->registers[$n]->value - 4;
                $this->memory->writeUInt32($addr, $this->registers[$m]);
                $this->writeRegister($n, U32::of($addr));
                return new GenericOperation($instruction, $opcode);

            // TST Rm,Rn
            case 0x2008:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("TST", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                if ($this->registers[$n]->band($this->registers[$m])->value !== 0) {
                    $this->srT = 0;
                } else {
                    $this->srT = 1;
                }
                return new GenericOperation($instruction, $opcode);

            // AND <REG_M>,<REG_N>
            case 0x2009:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("AND", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$n]->band($this->registers[$m]));
                return new GenericOperation($instruction, $opcode);

            // OR Rm,Rn
            case 0x200b:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("OR", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$n]->bor($this->registers[$m]));
                return new GenericOperation($instruction, $opcode);

            // CMP/EQ <REG_M>,<REG_N>
            case 0x3000:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("CMP/EQ", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                if ($this->registers[$n]->equals($this->registers[$m])) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return new GenericOperation($instruction, $opcode);

            // CMP/HS <REG_M>,<REG_N>
            case 0x3002:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("CMP/HS", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                // TODO: Double check signed to unsigned convertion
                if ($this->registers[$n]->greaterThanOrEqual($this->registers[$m])) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                return new GenericOperation($instruction, $opcode);

            // CMP/GE <REG_M>,<REG_N>
            case 0x3003:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("CMP/GE", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                // TODO: Create SInt value object
                if ($this->registers[$n]->signedValue() >= $this->registers[$m]->signedValue()) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                return new GenericOperation($instruction, $opcode);

            // CMP/GT <REG_M>,<REG_N>
            case 0x3007:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("CMP/GT", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                if ($this->registers[$n]->signedValue() > $this->registers[$m]->signedValue()) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return new GenericOperation($instruction, $opcode);

            // SUB <REG_M>,<REG_N>
            case 0x3008:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("SUB", ["R$m","R$n"]);
                // TODO: Use SInt value object
                $result = U32::of(($this->registers[$n]->value - $this->registers[$m]->value) & 0xffffffff);
                $this->writeRegister($n, $result);
                return new GenericOperation($instruction, $opcode);

            // ADD Rm,Rn
            case 0x300c:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("ADD", ["R$m","R$n"]);
                $result = $this->registers[$n]->add($this->registers[$m], allowOverflow: true);
                $this->writeRegister($n, $result);
                return new GenericOperation($instruction, $opcode);

            // ADDC Rm,Rn
            case 0x300e:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("ADDC", ["R$m","R$n"]);
                $tmp1 = $this->registers[$n]->add($this->registers[$m], allowOverflow: true);
                $tmp0 = U32::of($this->registers[$n]->value);
                $this->writeRegister($n, $tmp1->add($this->srT, allowOverflow: true));
                if ($tmp0->greaterThan($tmp1)) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                if ($tmp1->greaterThan($this->registers[$n])) {
                    $this->srT = 1;
                }
                return new GenericOperation($instruction, $opcode);

            // SHAD <REG_M>,<REG_N>
            case 0x400c:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("SHAD", ["R$m","R$n"]);
                $shiftRegister = $this->registers[$m];
                $valueRegister = $this->registers[$n];
                $hasSign = $shiftRegister->band(1 << 31)->isNotZero();
                // Left shift (sign bit is not set)
                if (!$hasSign) {
                    $this->writeRegister($n, $valueRegister->shiftLeft($shiftRegister->band(0x1f)->value));
                }
                // Full right shift (sign bit is set, shift is 0)
                elseif ($shiftRegister->band(0x1f)->isZero()) {
                    // FIXME: Signed shift right
                    $this->writeRegister($n, $valueRegister->shiftRight(31));
                }
                // Right shift (sign bit is set, shift is not 0)
                else {
                    $shift = (~$shiftRegister->value & 0x1f) + 1;
                    $this->writeRegister($n, $valueRegister->shiftRight($shift));
                }
                return new GenericOperation($instruction, $opcode);

            // SHLD <REG_M>,<REG_N>
            case 0x400c:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("SHLD", ["R$m","R$n"]);
                $shiftRegister = $this->registers[$m];
                $valueRegister = $this->registers[$n];
                $hasSign = $shiftRegister->band(1 << 31)->isNotZero();
                // Left shift (sign bit is not set)
                if (!$hasSign) {
                    $this->writeRegister($n, $valueRegister->shiftLeft($shiftRegister->band(0x1f)->value));
                }
                // Full right shift (sign bit is set, shift is 0)
                elseif ($shiftRegister->band(0x1f)->isZero()) {
                    $this->writeRegister($n, U32::of(0));
                }
                // Right shift (sign bit is set, shift is not 0)
                else {
                    $shift = (~$shiftRegister->value & 0x1f) + 1;
                    $this->writeRegister($n, $valueRegister->shiftRight($shift));
                }
                return new GenericOperation($instruction, $opcode);

            // MOV.B @Rm,Rn
            case 0x6000:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.B", ["@R$m","R$n"]);
                $source = $this->registers[$m];
                $value = $this->readUInt8($this->registers[$m]->value)->extend32();
                $this->writeRegister($n, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV.W @<REG_M>,<REG_N>
            case 0x6001:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.W", ["@R$m","R$n"]);
                $source = $this->registers[$m];
                $value = $this->readUInt16($this->registers[$m]->value)->extend32();
                $this->writeRegister($n, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV @Rm,Rn
            case 0x6002:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV", ["@R$m","R$n"]);
                $source = $this->registers[$m];
                $value = $this->readUInt32($this->registers[$m]->value);
                $this->writeRegister($n, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV Rm,Rn
            case 0x6003:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]);
                return new GenericOperation($instruction, $opcode);

            // MOV.B @<REG_M>+, <REG_N>
            case 0x6004:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("MOV.B", ["@R$m+","R$n"]);
                $source = $this->registers[$m];
                $value = $this->readUInt8($source->value)->extend32();
                $this->writeRegister($n, $value);
                if ($n != $m) {
                    $this->registers[$m] = $this->registers[$m]->add(1);
                }
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV @<REG_M>+,<REG_N>
            case 0x6006:
                [$n, $m] = getNM($instruction);
                $source = $this->registers[$m];
                $value = $this->readUInt32($source->value);
                $this->writeRegister($n, $value);
                $this->emitDisasm("MOV", ["@R$m+","R$n"]);
                if ($n != $m) {
                    $this->registers[$m] = $this->registers[$m]->add(4);
                }
                return new ReadOperation($instruction, $opcode, $source, $value);

            // NEG <REG_M>,<REG_N>
            case 0x600b:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("NEG", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->invert());
                return new GenericOperation($instruction, $opcode);

            // EXTU.B <REG_M>,<REG_N>
            case 0x600c:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("EXTU.B", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->trunc8()->u32());
                return new GenericOperation($instruction, $opcode);

            // EXTU.W <REG_M>,<REG_N>
            case 0x600d:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("EXTU.W", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->trunc16()->u32());
                return new GenericOperation($instruction, $opcode);

            // EXTS.B <REG_M>,<REG_N>
            case 0x600e:
                [$n, $m] = getNM($instruction);
                $this->emitDisasm("EXTS.B", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->trunc8()->extend32());
                return new GenericOperation($instruction, $opcode);

            // FADD <FREG_M>,<FREG_N>
            case 0xf000:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->emitDisasm("FADD", ["FR$m", "FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$n] + $this->fregisters[$m]);
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                //     double d = getDRn(op) - getDRm(op);
                //     d = fixNaN64(d);
                //     setDRn(op, d);
                // }
                return new GenericOperation($instruction, $opcode);

            // FSUB <FREG_M>,<FREG_N>
            case 0xf001:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->emitDisasm("FSUB", ["FR$m", "FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$n] - $this->fregisters[$m]);
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                //     double d = getDRn(op) - getDRm(op);
                //     d = fixNaN64(d);
                //     setDRn(op, d);
                // }
                return new GenericOperation($instruction, $opcode);

            // FMUL <FREG_M>,<FREG_N>
            case 0xf002:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->emitDisasm("FMUL", ["FR$m", "FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$n] * $this->fregisters[$m]);
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                //     double d = getDRn(op) - getDRm(op);
                //     d = fixNaN64(d);
                //     setDRn(op, d);
                // }
                return new GenericOperation($instruction, $opcode);

            // FDIV <FREG_M>,<FREG_N>
            case 0xf003:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->emitDisasm("FDIV", ["FR$m", "FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$n] / $this->fregisters[$m]);
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                // }
                return new GenericOperation($instruction, $opcode);

            // FCMP/GT <FREG_M>,<FREG_N>
            case 0xf005:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FCMP/GT", ["FR$m", "FR$n"]);

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
                return new GenericOperation($instruction, $opcode);

            // FMOV.S @(R0, <REG_M>),<FREG_N>
            case 0xf006:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FMOV.S", ["@(R0, R$m)", "FR$n"]);
                    $value = $this->readUInt32($this->registers[$m]->value, $this->registers[0]->value)->value;
                    $this->writeFloatRegister($n, unpack('f', pack('L', $value))[1]);
                // } else {
                    // ...
                // }
                return new ReadOperation($instruction, $opcode, $this->registers[$m]->add($this->registers[0]), U32::of($value));

            // FMOV.S <FREG_M>,@(R0,<REG_N>)
            case 0xf007:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FMOV.S", ["FR$m", "@(R0,R$n)"]);
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->writeUInt32($this->registers[$n]->value, $this->registers[0]->value, U32::of($value));
                // } else {
                    // ...
                // }
                return new WriteOperation($instruction, $opcode, $this->registers[$n]->add($this->registers[0]), U32::of($value));

            // FMOV.S @<REG_M>,<FREG_N>
            case 0xf008:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FMOV.S", ["@R$m", "FR$n"]);
                    $value = $this->readUInt32($this->registers[$m]->value)->value;
                    $this->writeFloatRegister($n, unpack('f', pack('L', $value))[1]);
                // } else {
                    // ...
                // }
                return new ReadOperation($instruction, $opcode, $this->registers[$m], U32::of($value));

            // FMOV.S @<REG_M>+,<FREG_N>
            case 0xf009:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FMOV.S", ["@R$m+", "FR$n"]);
                    // TODO: Use read proxy?
                    $source = $this->registers[$m];
                    $value = $this->readUInt32($source->value)->value;
                    $this->writeFloatRegister($n, unpack('f', pack('L', $value))[1]);
                    $this->registers[$m] = $this->registers[$m]->add(4);
                // } else {
                    // ...
                // }
                return new ReadOperation($instruction, $opcode, $source, U32::of($value));

            // FMOV.S <FREG_M>,@<REG_N>
            case 0xf00a:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FMOV.S", ["FR$m", "@R$n"]);
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->writeUInt32($this->registers[$n]->value, 0, U32::of($value));
                // } else {
                    // ...
                // }
                return new WriteOperation($instruction, $opcode, $this->registers[$n], U32::of($value));

            // FMOV.S <FREG_M>,@-<REG_N>
            case 0xf00b:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FMOV.S", ["FR$m", "@-R$n"]);
                    $addr = $this->registers[$n]->sub(4);
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->memory->writeUInt32($addr->value, U32::of($value));
                    $this->writeRegister($n, $addr);
                // } else {
                    // ...
                // }
                return new GenericOperation($instruction, $opcode);

            // FMOV <FREG_M>,<FREG_N>
            case 0xf00c:
                // if (fpscr.SZ == 0)
                // {
                    [$n, $m] = getNM($instruction);
                    $this->emitDisasm("FMOV", ["FR$m", "FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$m]);
                // }
                // else
                // {
                //     // TODO
                // }
                return new GenericOperation($instruction, $opcode);

            // FMAC <FREG_0>,<FREG_M>,<FREG_N>
            case 0xf00e:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->emitDisasm("FMAC", ["FR0,FR$m,FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$n] + $this->fregisters[0] * $this->fregisters[$m]);
                    // TODO: NaN signaling bit
                    // CHECK_FPU_32(fr[n]);
                // }
                // else
                // {
                //     double d = getDRn(op) - getDRm(op);
                //     d = fixNaN64(d);
                //     setDRn(op, d);
                // }
                return new GenericOperation($instruction, $opcode);
        }

        switch ($opcode = $instruction & 0xff00) {
            // MOV.B R0,@(<disp>,<REG_M>)
            case 0x8000:
                $m = getM($instruction);
                $disp = getImm4($instruction)->value;
                $this->emitDisasm("MOV.B", ["R0", "@($disp, R$m)"]);
                $this->writeUInt8($this->registers[$m]->value, $disp, $this->registers[0]->trunc8());
                return new WriteOperation($instruction, $opcode, $this->registers[$m]->add($disp), $this->registers[0]->trunc8());

            // MOV.W R0,@(<disp>,<REG_M>)
            case 0x8100:
                $m = getM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft()->value;
                $this->emitDisasm("MOV.W", ["R0", "@($disp, R$m)"]);
                $this->writeUInt16($this->registers[$m]->value, $disp, $this->registers[0]->trunc16());
                return new WriteOperation($instruction, $opcode, $this->registers[$m]->add($disp), $this->registers[0]->trunc16());

            // MOV.B @(<disp>, <REG_M>),R0
            case 0x8400:
                $m = getM($instruction);
                $disp = getImm4($instruction)->value;
                $this->emitDisasm("MOV.B", ["@($disp, R$m)", "R0"]);
                $source = $this->registers[$m]->add($disp);
                $value = $this->readUInt8($this->registers[$m]->value, $disp)->extend32();
                $this->writeRegister(0, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // MOV.W @(<disp>, <REG_M>),R0
            case 0x8500:
                $m = getM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft()->value;
                $this->emitDisasm("MOV.W", ["@($disp, R$m)", "R0"]);
                $source = $this->registers[$m]->add($disp);
                $value = $this->readUInt16($this->registers[$m]->value, $disp)->extend32();
                $this->writeRegister(0, $value);
                return new ReadOperation($instruction, $opcode, $source, $value);

            // CMP/EQ #<imm>,R0
            case 0x8800:
                $imm = getImm8($instruction);
                $this->emitDisasm("CMP/EQ", ["#{$imm->hitachiSignedHex()}", "R0"]);
                $this->logRegister(0);
                if ($this->registers[0]->equals($imm->extend32())) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return new GenericOperation($instruction, $opcode);

            // BT <bdisp8>
            case 0x8900:
                $target = branchTargetS8($instruction, $this->pc);
                $this->emitDisasm("BT", ["H'" . dechex($target)]);
                if ($this->srT !== 0) {
                    $this->pc = $target;
                }
                return new ControlFlowOperation($instruction, $opcode);

            // BF <bdisp8>
            case 0x8b00:
                $target = branchTargetS8($instruction, $this->pc);
                $this->emitDisasm("BF", ["H'" . dechex($target)]);
                if ($this->srT === 0) {
                    $this->pc = $target;
                }
                return new ControlFlowOperation($instruction, $opcode);

            // BT/S        <bdisp8>
            case 0x8d00:
                $newpc = branchTargetS8($instruction, $this->pc);
                $this->emitDisasm("BT/S", ["H'" . dechex($newpc) . ""]);
                if ($this->srT !== 0) {
                    $this->delayedPc = $newpc;
                }
                return new ControlFlowOperation($instruction, $opcode);

            // BF/S <bdisp8>
            case 0x8f00:
                $newpc = branchTargetS8($instruction, $this->pc);
                $this->emitDisasm("BF/S", ["H'" . dechex($newpc) . ""]);
                if ($this->srT === 0) {
                    $this->delayedPc = $newpc;
                }
                return new ControlFlowOperation($instruction, $opcode);

            // MOVA @(<disp>,PC),R0
            case 0xc700:
                /* TODO: Check other for u32 after shift */
                $disp = getImm8($instruction)->u32()->shiftLeft(2)->value;
                $this->emitDisasm("MOVA", ["@($disp,PC)", "R0"]);
                $this->writeRegister(
                    0,
                    U32::of(($this->pc + 2) & 0xfffffffc)->add($disp)
                );
                return new GenericOperation($instruction, $opcode);

            // TST #imm,R0
            case 0xc800:
                $imm = getImm8($instruction)->u32();
                $this->emitDisasm("TST", ["#H'{$imm->hex()}", "R0"]);
                $this->logRegister(0);
                if ($this->registers[0]->band($imm)->equals(0)) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return new GenericOperation($instruction, $opcode);

            // AND #imm,R0
            case 0xc900:
                $imm = getImm8($instruction)->u32();
                $this->emitDisasm("AND", ["#H'{$imm->hex()}", "R0"]);
                $this->writeRegister(0, $this->registers[0]->band($imm));
                return new GenericOperation($instruction, $opcode);

            // OR #imm,R0
            case 0xcb00:
                $imm = getImm8($instruction)->u32();
                $this->emitDisasm("OR", ["#H'{$imm->hex()}", "R0"]);
                $this->writeRegister(0, $this->registers[0]->bor($imm));
                return new GenericOperation($instruction, $opcode);
        }

        // f0ff
        switch ($opcode = $instruction & 0xf0ff) {
            // STS MACL,<REG_N>
            case 0x001a:
                $n = getN($instruction);
                $this->emitDisasm("STS", ["MACL", "R$n"]);
                $this->writeRegister($n, U32::of($this->macl));
                return new GenericOperation($instruction, $opcode);

            // BRAF <REG_N>
            case 0x0023:
                $n = getN($instruction);
                $this->emitDisasm("BRAF", ["R$n"]);
                $newpc = $this->registers[$n]->value + $this->pc + 2;

                //WARN : r[n] can change here

                $this->delayedPc = $newpc;
                return new ControlFlowOperation($instruction, $opcode);

            // MOVT <REG_N>
            case 0x0029:
                $n = getN($instruction);
                $this->emitDisasm("MOVT", ["R$n"]);
                $this->writeRegister($n, U32::of($this->srT));
                return new GenericOperation($instruction, $opcode);

            // STS FPUL,<REG_N>
            case 0x005a:
                $n = getN($instruction);
                $this->emitDisasm("STS", ["FPUL","R$n"]);
                $this->writeRegister($n, U32::of($this->fpul));
                return new GenericOperation($instruction, $opcode);

            case 0x002a:
                $n = getN($instruction);
                $this->emitDisasm("STS", ["PR","R$n"]);
                $this->writeRegister($n, U32::of($this->pr));
                return new GenericOperation($instruction, $opcode);

            // SHLL <REG_N>
            case 0x4000:
                $n = getN($instruction);
                $this->emitDisasm("SHLL", ["R$n"]);
                $this->srT = $this->registers[$n]->shiftRight(31)->value;
                $this->writeRegister($n, $this->registers[$n]->shiftLeft());
                return new GenericOperation($instruction, $opcode);

            // SHLR <REG_N>
            case 0x4001:
                $n = getN($instruction);
                $this->emitDisasm("SHLR", ["R$n"]);
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $this->writeRegister($n, $this->registers[$n]->shiftRight());
                return new GenericOperation($instruction, $opcode);

            // SHLL2 Rn
            case 0x4008:
                $n = getN($instruction);
                $this->emitDisasm("SHLL2", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftLeft(2));
                return new GenericOperation($instruction, $opcode);

            // SHLR2 Rn
            case 0x4009:
                $n = getN($instruction);
                $this->emitDisasm("SHLR2", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftRight(2));
                return new GenericOperation($instruction, $opcode);

            // JSR
            case 0x400b:
                $n = getN($instruction);
                $this->emitDisasm("JSR", ["@R$n"]);

                $newpr = $this->pc + 2;   //return after delayslot
                $newpc = $this->registers[$n]->value;

                $this->pr = $newpr;
                $this->delayedPc = $newpc;
                return new BranchOperation($instruction, $opcode, U32::of($newpc));

            // CMP/PZ <REG_N>
            case 0x4011:
                $n = getN($instruction);
                $this->emitDisasm("CMP/PZ", ["R$n"]);
                $this->logRegister($n);
                if ($this->registers[$n]->signedValue() >= 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return new GenericOperation($instruction, $opcode);

            // CMP/PL <REG_N>
            case 0x4015:
                $n = getN($instruction);
                $this->emitDisasm("CMP/PL", ["R$n"]);
                $this->logRegister($n);
                if ($this->registers[$n]->signedValue() > 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return new GenericOperation($instruction, $opcode);

            // SHLL8  Rn;
            case 0x4018:
                $n = getN($instruction);
                $this->emitDisasm("SHLL8", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftLeft(8));
                return new GenericOperation($instruction, $opcode);

            // SHLR8  Rn;
            case 0x4019:
                $n = getN($instruction);
                $this->emitDisasm("SHLR8", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftRight(8));
                return new GenericOperation($instruction, $opcode);

            // SHAR  Rn;
            case 0x4021:
                $n = getN($instruction);
                $this->emitDisasm("SHAR", ["R$n"]);
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $sign = $this->registers[$n]->band(0x80000000);
                $this->writeRegister($n, $this->registers[$n]->shiftRight()->bor($sign));
                return new GenericOperation($instruction, $opcode);

            // STS.L PR,@-<REG_N>
            case 0x4022:
                $n = getN($instruction);
                $this->emitDisasm("STS.L", ["PR", "@-R$n"]);
                $address = $this->registers[$n]->sub(4);
                $this->memory->writeUInt32($address->value, U32::of($this->pr));
                $this->writeRegister($n, $address);
                return new GenericOperation($instruction, $opcode);

            // LDS.L @<REG_N>+,PR
            case 0x4026:
                $n = getN($instruction);
                $this->emitDisasm("LDS.L", ["@R$n+", "PR"]);
                // TODO: Use read proxy?
                $this->pr = $this->memory->readUInt32($this->registers[$n]->value)->value;
                $this->registers[$n] = $this->registers[$n]->add(4);
                return new GenericOperation($instruction, $opcode);

            // SHLL16 Rn;
            case 0x4028:
                $n = getN($instruction);
                $this->emitDisasm("SHLL16", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftLeft(16));
                return new GenericOperation($instruction, $opcode);

            // JMP
            case 0x402b:
                $n = getN($instruction);
                $this->emitDisasm("JMP", ["R$n"]);
                $newpc = $this->registers[$n]->value;

                $this->delayedPc = $newpc;
                return new BranchOperation($instruction, $opcode, U32::of($newpc));

            // LDS <REG_M>,FPUL
            case 0x405a:
                $n = getN($instruction);
                $this->emitDisasm("LDS", ["R$n", "FPUL"]);
                $this->fpul = $this->registers[$n]->value;
                $hex = dechex($this->fpul);
                $this->emitAddLog("FPUL=H'$hex");
                return new GenericOperation($instruction, $opcode);

            // FSTS        FPUL,<FREG_N>
            case 0xf00d:
                $n = getN($instruction);
                $this->emitDisasm("FSTS", ["FPUL", "FR$n"]);
                $this->writeFloatRegister($n, unpack('f', pack('L', $this->fpul))[1]);
                return new GenericOperation($instruction, $opcode);

            // FLOAT       FPUL,<FREG_N>
            case 0xf02d:
                $n = getN($instruction);
                $this->emitDisasm("FLOAT", ["FPUL", "FR$n"]);
                $this->writeFloatRegister($n, (float) $this->fpul);
                return new GenericOperation($instruction, $opcode);

            // FTRC <FREG_N>,FPUL
            case 0xf03d:
                $n = getN($instruction);
                $this->emitDisasm("FTRC", ["FR$n", "FPUL"]);
                $this->fpul = ((int) $this->fregisters[$n]) & 0xffffffff;
                return new GenericOperation($instruction, $opcode);

            // FNEG <FREG_N>
            case 0xf04d:
                $n = getN($instruction);
                // if (fpscr.PR ==0)
                $this->emitDisasm("FNEG", ["FR$n"]);
                $this->writeFloatRegister($n, -$this->fregisters[$n]);
                // else
                return new GenericOperation($instruction, $opcode);

            // FLDI0
            case 0xf08d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }

                $n = getN($instruction);
                $this->emitDisasm("FLDI0", ["FR$n"]);
                $this->writeFloatRegister($n, 0.0);
                return new GenericOperation($instruction, $opcode);

            // FLDI1
            case 0xf09d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }

                $n = getN($instruction);
                $this->emitDisasm("FLDI1", ["FR$n"]);
                $this->writeFloatRegister($n, 1.0);
                return new GenericOperation($instruction, $opcode);
        }

        // f000
        switch ($opcode = $instruction & 0xf000) {
            // BRA <bdisp12>
            case 0xa000:
                $newpc = branchTargetS12($instruction, $this->pc);
                $newpcHex = dechex($newpc);
                $this->emitDisasm("BRA", ["H'$newpcHex"]);

                $this->delayedPc = $newpc;
                return new BranchOperation($instruction, $opcode, U32::of($newpc));

            // BSR <bdisp12>
            case 0xb000:
                $newpr = $this->pc + 2;   //return after delayslot
                $newpc = branchTargetS12($instruction, $this->pc);
                $newpcHex = dechex($newpc);
                $this->emitDisasm("BSR", ["H'$newpcHex"]);

                $this->pr = $newpr;
                $this->delayedPc = $newpc;
                return new BranchOperation($instruction, $opcode, U32::of($newpc));

            // MOV.L @(<disp>,PC),<REG_N>
            case 0xd000:
                $n = getN($instruction);
                $disp = getImm8($instruction)->u32()->shiftLeft(2)->value;
                $this->emitDisasm("MOV.L", ["@($disp,PC)","R$n"]);

                $addr = (($this->pc + 2) & 0xFFFFFFFC);
                $data = $this->readUInt32($addr, $disp);
                $this->writeRegister($n, $data);
                return new ReadOperation($instruction, $opcode, U32::of($addr + $disp), $data);
        }

        throw new \Exception("Unknown instruction " . str_pad(dechex($instruction), 4, '0', STR_PAD_LEFT));
    }

    public function setRegister(int $n, U32 $value): void
    {
        $this->registers[$n] = $value;
    }

    public function getRegister(int $n): U32
    {
        return $this->registers[$n];
    }

    public function getFloatRegister(int $n): float
    {
        return $this->fregisters[$n];
    }

    public function getPc(): int
    {
        return $this->pc;
    }

    public function getPr(): int
    {
        return $this->pr;
    }

    public function setPc(int $pc): void
    {
        $this->pc = $pc;
    }

    private function writeRegister(int $n, U32|Relocation $value): void
    {
        if ($value instanceof Relocation) {
            throw new \Exception("Trying to write relocation $value->name to R$n");
        }

        $this->registers[$n] = $value;

        $this->emitAddLog("R$n=H'{$value->shortHex()}");
    }

    public function setFloatRegister(int $n, float $value): void
    {
        $this->fregisters[$n] = $value;
    }

    private function writeFloatRegister(int $n, float $value): void
    {
        $this->fregisters[$n] = $value;

        $this->emitAddLog("FR$n=$value");
    }

    // public function hexdump(): void
    // {
    //     echo "PC: " . dechex($this->pc) . "\n";
    //     // print_r($this->registers);

    //     // return;

    //     // TODO: Unhardcode memory size
    //     for ($i=0x1660; $i < 0x1660 + 0x400; $i++) {
    //         if ($i % 16 === 0) {
    //             echo "\n";
    //             echo str_pad(dechex($i), 4, '0', STR_PAD_LEFT) . ': ';
    //         } else if ($i !== 0 && $i % 4 === 0) {
    //             echo " ";
    //         }

    //         if ($this->getRelocationAt($i)) {
    //             echo "RR RR RR RR ";
    //             $i += 3;
    //             continue;
    //         }

    //         echo str_pad(dechex($this->memory->readUInt8($i)->value), 2, '0', STR_PAD_LEFT) . ' ';
    //     }

    //     for ($i=0x800000; $i < 0x800000 + 0x40; $i++) {
    //         if ($i % 16 === 0) {
    //             echo "\n";
    //             echo str_pad(dechex($i), 4, '0', STR_PAD_LEFT) . ': ';
    //         } else if ($i !== 0 && $i % 4 === 0) {
    //             echo " ";
    //         }

    //         if ($this->getRelocationAt($i)) {
    //             echo "RR RR RR RR ";
    //             $i += 3;
    //             continue;
    //         }

    //         echo str_pad(dechex($this->memory->readUInt8($i)->value), 2, '0', STR_PAD_LEFT) . ' ';
    //     }
    // }

    private function logRegister(int $index): void
    {
        $value = $this->registers[$index];
        $this->emitAddLog("R$index:H'{$value->shortHex()}");
    }

    /**
     * @param int[] $registers
     */
    private function logRegisters(array $registers): void
    {
        foreach ($registers as $index) {
            $this->logRegister($index);
        }
    }

    public function onDisasm(Closure $callback): void
    {
        $this->disasmCallback = $callback;
    }

    public function onAddLog(Closure $callback): void
    {
        $this->addLogCallback = $callback;
    }

    /**
     * @param string[] $operands
     */
    public function emitDisasm(string $mnemonic, array $operands = []): void
    {
        if (isset($this->disasmCallback)) {
            ($this->disasmCallback)($this, $mnemonic, $operands);
        }
    }

    public function emitAddLog(string $message): void
    {
        if (isset($this->addLogCallback)) {
            ($this->addLogCallback)($this, $message);
        }
    }

    protected function readUInt(int $addr, int $offset, int $size): U8|U16|U32
    {
        $displacedAddr = $addr + $offset;

        $value = match ($size) {
            U8::BIT_COUNT => $this->memory->readUInt8($displacedAddr),
            U16::BIT_COUNT => $this->memory->readUInt16($displacedAddr),
            U32::BIT_COUNT => $this->memory->readUInt32($displacedAddr),
            default => throw new \Exception("Unsupported read size $size", 1),
        };

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

        match (get_class($value)) {
            U8::class => $this->memory->writeUInt8($displacedAddr, $value),
            U16::class => $this->memory->writeUInt16($displacedAddr, $value),
            U32::class => $this->memory->writeUInt32($displacedAddr, $value),
            default => throw new \Exception("Unsupported write size " . $value::BIT_COUNT, 1),
        };
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

    public function getMemory(): BinaryMemory
    {
        return $this->memory;
    }

    public function getDisasmPc(): int
    {
        return $this->disasmPc;
    }

    public function inDelaySlot(): bool
    {
        return $this->inDelaySlot;
    }

    public function cancelDelayedBranch(): void
    {
        $this->delayedPc = null;
    }

    public function nextIsDelaySlot(): bool
    {
        return $this->delayedPc !== null;
    }
}
