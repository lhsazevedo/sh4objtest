<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExportSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\ArgumentType;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\StackOffset;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U4;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\UInt;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReadExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\StringWriteExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\WriteExpectation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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

    private bool $shouldDisasm = false;

    private ?string $disasm = null;

    private ?string $delaySlotDisasm = null;

    /** @var string[] */
    private array $registerLog = [];

    /** @var string[] */
    private array $delaySlotRegisterLog = [];

    /** @var string[] */
    private array $messages = [];

    /** @var string[] */
    private array $delaySlotMessages = [];

    private int $disasmPc;

    private bool $inDelaySlot = false;
    /**
     * @param AbstractExpectation[] $expectations
     * */ 
    public function __construct(
        private InputInterface $input,
        private OutputInterface $output,

        private ParsedObject $object,

        private array $expectations,

        private Entry $entry,

        private bool $forceStop,

        /** @var TestRelocation[] */
        private array $testRelocations,

        /** @var Relocation[] */
        private array $unresolvedRelocations,

        BinaryMemory $memory,
    )
    {
        $this->pendingExpectations = $expectations;

        $this->memory = $memory;

        // Default state
        $this->pc = 0;
        for ($i = 0; $i < 16; $i++) {
            $this->registers[$i] = U32::of(0);
        }

        // Stack pointer
        $this->registers[15] = U32::of(1024 * 1024 * 16 - 4);
    }

    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            $instruction = $this->readInstruction($this->pc);

            $this->disasmPc = $this->pc;
            $this->pc += 2;

            try {
                $this->executeInstruction($instruction);
            } catch (\Throwable $e) {
                $this->outputMessages();
                throw $e;
            }

            $this->outputMessages();

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
        if ($expectedReturn !== null && !$actualReturn->equals($expectedReturn)) {
            throw new ExpectationException("Unexpected return value $actualReturn, expecting $expectedReturn");
        }

        // TODO: returns and float returns are mutually exclusive
        if ($this->entry->floatReturn !== null) {
            $expectedFloatReturn = $this->entry->floatReturn;
            $actualFloatReturn = $this->fregisters[0];
            $expectedDecRepresentation = unpack('L', pack('f', $expectedFloatReturn))[1];
            $actualDecRepresentation = unpack('L', pack('f', $actualFloatReturn))[1];

            if ($actualDecRepresentation !== $expectedDecRepresentation) {
                throw new ExpectationException("Unexpected return value $actualFloatReturn, expecting $expectedFloatReturn");
            }
        }

        $count = count($this->expectations);
        if ($expectedReturn || $this->entry->floatReturn !== null) {
            $count++;
        }
        $this->output->writeln("\n<bg=bright-green;options=bold> PASS </> <fg=green>$count expectations fulfilled</>\n");
    }

    public function readInstruction(int $address): U16
    {
        return $this->memory->readUInt16($address);
    }

    public function executeDelaySlot(): void
    {
        $this->inDelaySlot = true;

        // TODO: refactor duplicated code
        $instruction = $this->readInstruction($this->pc);

        $this->disasmPc = $this->pc;
        $this->pc += 2;
        $this->executeInstruction($instruction);

        $this->inDelaySlot = false;
    }

    public function executeInstruction(U16 $instruction): void
    {
        // TODO: Use U16 value directly?
        $instruction = $instruction->value;

        switch ($instruction) {
            // NOP
            case 0x0009:
                $this->disasm("NOP");
                // Do nothing
                return;

            // RTS
            case 0x000b:
                $this->disasm("RTS");
                $this->executeDelaySlot();
                $this->running = false;
                return;
        }

        switch ($instruction & 0xf000) {
            // MOV.L <REG_M>,@(<disp>,<REG_N>)
            case 0x1000:
                [$n, $m] = getNM($instruction);
                // TODO: Extract to Instruction Value Object
                $disp = getImm4($instruction)->u32()->shiftLeft(2)->value;
                $this->disasm("MOV.L", ["R$m", "@($disp,R$n)"]);
                $this->writeUInt32($this->registers[$n]->value, $disp, $this->registers[$m]);
                return;

            // MOV.L  @(<disp>,<REG_M>),<REG_N>
            case 0x5000:
                [$n, $m] = getNM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft(2)->value;
                $this->disasm("MOV.L", ["@($disp,R$m)","R$n"]);
                $this->writeRegister($n, $this->readUInt32($this->registers[$m]->value, $disp));
                return;

            // ADD #imm,Rn
            case 0x7000:
                $n = getN($instruction);
                $imm = getImm8($instruction);
                $this->disasm("ADD", ["#{$imm->hitachiSignedHex()}","R$n"]);

                // TODO: Use SInt value object
                $this->writeRegister($n, $this->registers[$n]->add($imm->extend32(), allowOverflow: true));
                return;

            // MOV.W @(<disp>,PC),<REG_N>
            case 0x9000:
                $n = getN($instruction);
                $disp = getImm8($instruction)->u32()->shiftLeft()->value;
                $this->disasm("MOV.W", ["@($disp,PC)","R$n"]);
                $this->writeRegister($n, $this->readUInt16($this->pc + 2, $disp)->extend32());
                return;

            // MOV #imm,Rn
            case 0xe000:
                $imm = getImm8($instruction);
                $n = getN($instruction);
                $this->disasm("MOV", ["#{$imm->hitachiSignedHex()}","R$n"]);
                $this->writeRegister($n, $imm->extend32());
                return;
        }

        switch ($instruction & 0xf1ff) {
            // TODO
        }

        switch ($instruction & 0xf00f) {
            // MOV.W <REG_M>, @(R0, <REG_N>)
            case 0x0005:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.W", ["R$m", "@(R0,R$n)"]);
                $this->writeUInt16($this->registers[$n]->value, $this->registers[0]->value, $this->registers[$m]->trunc16());
                return;

            // MOV.L <REG_M>, @(R0,<REG_N>)
            case 0x0006:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.L", ["R$m", "@(R0,R$n)"]);
                // TODO: Is R0 always the offset?
                // TODO2: Why this matters?
                $this->writeUInt32($this->registers[$n]->value, $this->registers[0]->value, $this->registers[$m]);
                return;

            // MUL.L <REG_M>,<REG_N>
            case 0x0007:
                [$n, $m] = getNM($instruction);
                $this->disasm("MUL.L", ["R$m","R$n"]);
                $result = $this->registers[$n]->mul($this->registers[$m]);
                $this->macl = $result->value;
                $this->addLog("MACL={$result->readable()}");
                return;

            // MOV.B @(R0,<REG_M>),<REG_N>
            case 0x000c:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.B", ["@(R0, R$m)","R$n"]);
                $value = $this->readUInt8($this->registers[0]->value, $this->registers[$m]->value);
                $this->writeRegister($n, $value->extend32());
                return;

            // MOV.W @(R0,<REG_M>),<REG_N>
            case 0x000d:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.W", ["@(R0,R$m)","R$n"]);
                $value = $this->readUInt16($this->registers[0]->value, $this->registers[$m]->value);
                $this->writeRegister($n, $value->extend32());
                return;

            // MOV.L @(R0,<REG_M>),<REG_N>
            case 0x000e:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.L", ["@(R0,R$m)","R$n"]);
                $this->writeRegister($n, $this->readUInt32($this->registers[0]->value, $this->registers[$m]->value));
                return;

            // MOV.B Rm,@Rn
            case 0x2000:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.B", ["R$m", "@R$n"]);
                $addr = $this->registers[$n];
                $this->writeUInt8($this->registers[$n]->value, 0, $this->registers[$m]->trunc8());
                return;

            // MOV.W Rm,@Rn
            case 0x2001:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.W", ["R$m", "@R$n"]);
                $addr = $this->registers[$n];
                $this->writeUInt16($this->registers[$n]->value, 0, $this->registers[$m]->trunc16());
                return;

            // MOV.L Rm,@Rn
            case 0x2002:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.L", ["R$m", "@R$n"]);
                $addr = $this->registers[$n];
                $this->writeUInt32($this->registers[$n]->value, 0, $this->registers[$m]);
                return;

            // MOV.L Rm,@-Rn
            case 0x2006:
                $n = getN($instruction);
                $m = getM($instruction);
                $this->disasm("MOV.L", ["R$m", "@-R$n"]);   
                $addr = $this->registers[$n]->value - 4;
                $this->memory->writeUInt32($addr, $this->registers[$m]);
                $this->writeRegister($n, U32::of($addr));
                return;

            // TST Rm,Rn
            case 0x2008:
                [$n, $m] = getNM($instruction);
                $this->disasm("TST", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                if ($this->registers[$n]->band($this->registers[$m])->value !== 0) {
                    $this->srT = 0;
                } else {
                    $this->srT = 1;
                }
                return;

            // AND <REG_M>,<REG_N>
            case 0x2009:
                [$n, $m] = getNM($instruction);
                $this->disasm("AND", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$n]->band($this->registers[$m]));
                return;

            // OR Rm,Rn
            case 0x200b:
                [$n, $m] = getNM($instruction);
                $this->disasm("OR", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$n]->bor($this->registers[$m]));
                return;

            // CMP/EQ <REG_M>,<REG_N>
            case 0x3000:
                [$n, $m] = getNM($instruction);
                $this->disasm("CMP/EQ", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                if ($this->registers[$n]->equals($this->registers[$m])) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return;

            // CMP/HS <REG_M>,<REG_N>
            case 0x3002:
                [$n, $m] = getNM($instruction);
                $this->disasm("CMP/HS", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                // TODO: Double check signed to unsigned convertion
                if ($this->registers[$n]->greaterThanOrEqual($this->registers[$m])) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                return;

            // CMP/GE <REG_M>,<REG_N>
            case 0x3003:
                [$n, $m] = getNM($instruction);
                $this->disasm("CMP/GE", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                // TODO: Create SInt value object
                if ($this->registers[$n]->signedValue() >= $this->registers[$m]->signedValue()) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                return;

            // CMP/GT <REG_M>,<REG_N>
            case 0x3007:
                [$n, $m] = getNM($instruction);
                $this->disasm("CMP/GT", ["R$m","R$n"]);
                $this->logRegisters([$m, $n]);
                if ($this->registers[$n]->signedValue() > $this->registers[$m]->signedValue()) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return;

            // SUB <REG_M>,<REG_N>
            case 0x3008:
                [$n, $m] = getNM($instruction);
                $this->disasm("SUB", ["R$m","R$n"]);
                // TODO: Use SInt value object
                $result = U32::of(($this->registers[$n]->value - $this->registers[$m]->value) & 0xffffffff);
                $this->writeRegister($n, $result);
                return;

            // ADD Rm,Rn
            case 0x300c:
                [$n, $m] = getNM($instruction);
                $this->disasm("ADD", ["R$m","R$n"]);
                $result = $this->registers[$n]->add($this->registers[$m], allowOverflow: true);
                $this->writeRegister($n, $result);
                return;

            // ADDC Rm,Rn
            case 0x300e:
                [$n, $m] = getNM($instruction);
                $this->disasm("ADDC", ["R$m","R$n"]);
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
                return;

            // SHAD <REG_M>,<REG_N>
            case 0x400c:
                [$n, $m] = getNM($instruction);
                $this->disasm("SHAD", ["R$m","R$n"]);
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
                return;

            // SHLD <REG_M>,<REG_N>
            case 0x400c:
                [$n, $m] = getNM($instruction);
                $this->disasm("SHLD", ["R$m","R$n"]);
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
                return;

            // MOV.B @Rm,Rn
            case 0x6000:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.B", ["@R$m","R$n"]);
                $this->writeRegister($n, $this->readUInt8($this->registers[$m]->value)->extend32());
                return;

            // MOV.W @<REG_M>,<REG_N>
            case 0x6001:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.W", ["@R$m","R$n"]);
                $this->writeRegister($n, $this->readUInt16($this->registers[$m]->value)->extend32());
                return;

            // MOV @Rm,Rn
            case 0x6002:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV", ["@R$m","R$n"]);
                $this->writeRegister($n, $this->readUInt32($this->registers[$m]->value));
                return;

            // MOV Rm,Rn
            case 0x6003:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]);
                return;

            // MOV.B @<REG_M>+, <REG_N>
            case 0x6004:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.B", ["@R$m+","R$n"]);
                $value = $this->readUInt8($this->registers[$m]->value);
                $this->writeRegister($n, $value->extend32());
                if ($n != $m) {
                    $this->registers[$m] = $this->registers[$m]->add(1);
                }
                return;

            // MOV @<REG_M>+,<REG_N>
            case 0x6006:
                [$n, $m] = getNM($instruction);
                $this->writeRegister($n, $this->readUInt32($this->registers[$m]->value));
                $this->disasm("MOV", ["@R$m+","R$n"]);
                if ($n != $m) {
                    $this->registers[$m] = $this->registers[$m]->add(4);
                }
                return;

            // NEG <REG_M>,<REG_N>
            case 0x600b:
                [$n, $m] = getNM($instruction);
                $this->disasm("NEG", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->invert());
                return;

            // EXTU.B <REG_M>,<REG_N>
            case 0x600c:
                [$n, $m] = getNM($instruction);
                $this->disasm("EXTU.B", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->trunc8()->u32());
                return;

            // EXTU.W <REG_M>,<REG_N>
            case 0x600d:
                [$n, $m] = getNM($instruction);
                $this->disasm("EXTU.W", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->trunc16()->u32());
                return;

            // EXTS.B <REG_M>,<REG_N>
            case 0x600e:
                [$n, $m] = getNM($instruction);
                $this->disasm("EXTS.B", ["R$m","R$n"]);
                $this->writeRegister($n, $this->registers[$m]->trunc8()->extend32());
                return;

            // FADD <FREG_M>,<FREG_N>
            case 0xf000:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->disasm("FADD", ["FR$m", "FR$n"]);
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
                return;

            // FSUB <FREG_M>,<FREG_N>
            case 0xf001:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->disasm("FSUB", ["FR$m", "FR$n"]);
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
                return;

            // FMUL <FREG_M>,<FREG_N>
            case 0xf002:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->disasm("FMUL", ["FR$m", "FR$n"]);
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
                return;

            // FDIV <FREG_M>,<FREG_N>
            case 0xf003:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->disasm("FDIV", ["FR$m", "FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$n] / $this->fregisters[$m]);
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
                    $this->disasm("FCMP/GT", ["FR$m", "FR$n"]);

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
                    $this->disasm("FMOV.S", ["@(R0, R$m)", "FR$n"]);
                    $value = $this->readUInt32($this->registers[$m]->value, $this->registers[0]->value)->value;
                    $this->writeFloatRegister($n, unpack('f', pack('L', $value))[1]);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@(R0,<REG_N>)
            case 0xf007:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->disasm("FMOV.S", ["FR$m", "@(R0,R$n)"]);
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
                    $this->disasm("FMOV.S", ["@R$m", "FR$n"]);
                    $value = $this->readUInt32($this->registers[$m]->value)->value;
                    $this->writeFloatRegister($n, unpack('f', pack('L', $value))[1]);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S @<REG_M>+,<FREG_N>
            case 0xf009:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->disasm("FMOV.S", ["@R$m+", "FR$n"]);
                    // TODO: Use read proxy?
                    $value = $this->readUInt32($this->registers[$m]->value)->value;
                    $this->writeFloatRegister($n, unpack('f', pack('L', $value))[1]);
                    $this->registers[$m] = $this->registers[$m]->add(4);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@<REG_N>
            case 0xf00a:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->disasm("FMOV.S", ["FR$m", "@R$n"]);
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->writeUInt32($this->registers[$n]->value, 0, U32::of($value));
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@-<REG_N>
            case 0xf00b:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $this->disasm("FMOV.S", ["FR$m", "@-R$n"]);
                    $addr = $this->registers[$n]->sub(4);
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->memory->writeUInt32($addr->value, U32::of($value));
                    $this->writeRegister($n, $addr);
                // } else {
                    // ...
                // }
                return;

            // FMOV <FREG_M>,<FREG_N>
            case 0xf00c:
                // if (fpscr.SZ == 0)
                // {
                    [$n, $m] = getNM($instruction);
                    $this->disasm("FMOV", ["FR$m", "FR$n"]);
                    $this->writeFloatRegister($n, $this->fregisters[$m]);
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
                    $this->disasm("FMAC", ["FR0,FR$m,FR$n"]);
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
                return;
        }

        switch ($instruction & 0xff00) {
            // MOV.B R0,@(<disp>,<REG_M>)
            case 0x8000:
                $m = getM($instruction);
                $disp = getImm4($instruction)->value;
                $this->disasm("MOV.B", ["R0", "@($disp, R$m)"]);
                $this->writeUInt8($this->registers[$m]->value, $disp, $this->registers[0]->trunc8());
                return;

            // MOV.W R0,@(<disp>,<REG_M>)
            case 0x8100:
                $m = getM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft()->value;
                $this->disasm("MOV.W", ["R0", "@($disp, R$m)"]);
                $this->writeUInt16($this->registers[$m]->value, $disp, $this->registers[0]->trunc16());
                return;

            // MOV.B @(<disp>, <REG_M>),R0
            case 0x8400:
                $m = getM($instruction);
                $disp = getImm4($instruction)->value;
                $this->disasm("MOV.B", ["@($disp, R$m)", "R0"]);
                $this->writeRegister(0, $this->readUInt8($this->registers[$m]->value, $disp)->extend32());
                return;

            // MOV.W @(<disp>, <REG_M>),R0
            case 0x8500:
                $m = getM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft()->value;
                $this->disasm("MOV.W", ["@($disp, R$m)", "R0"]);
                $this->writeRegister(0, $this->readUInt16($this->registers[$m]->value, $disp)->extend32());
                return;

            // CMP/EQ #<imm>,R0
            case 0x8800:
                $imm = getImm8($instruction);
                $this->disasm("CMP/EQ", ["#{$imm->hitachiSignedHex()}", "R0"]);
                $this->logRegister(0);
                if ($this->registers[0]->equals($imm->extend32())) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return;

            // BT <bdisp8>
            case 0x8900:
                $target = branchTargetS8($instruction, $this->pc);
                $this->disasm("BT", ["H'" . dechex($target)]);
                if ($this->srT !== 0) {
                    $this->pc = $target;
                }
                return;

            // BF <bdisp8>
            case 0x8b00:
                $target = branchTargetS8($instruction, $this->pc);
                $this->disasm("BF", ["H'" . dechex($target)]);
                if ($this->srT === 0) {
                    $this->pc = $target;
                }
                return;

            // BT/S        <bdisp8>
            case 0x8d00:
                $newpc = branchTargetS8($instruction, $this->pc);
                $this->disasm("BT/S", ["H'" . dechex($newpc) . ""]);
                if ($this->srT !== 0) {
                    $this->executeDelaySlot();
                    $this->pc = $newpc;
                }
                return;

            // BF/S <bdisp8>
            case 0x8f00:
                $newpc = branchTargetS8($instruction, $this->pc);
                $this->disasm("BF/S", ["H'" . dechex($newpc) . ""]);
                if ($this->srT === 0) {
                    $this->executeDelaySlot();
                    $this->pc = $newpc;
                }
                return;

            // MOVA @(<disp>,PC),R0
            case 0xc700:
                /* TODO: Check other for u32 after shift */
                $disp = getImm8($instruction)->u32()->shiftLeft(2)->value;
                $this->disasm("MOVA", ["@($disp,PC)", "R0"]);
                $this->writeRegister(
                    0,
                    U32::of(($this->pc + 2) & 0xfffffffc)->add($disp)
                );
                return;

            // TST #imm,R0
            case 0xc800:
                $imm = getImm8($instruction)->u32();
                $this->disasm("TST", ["#H'{$imm->hex()}", "R0"]);
                $this->logRegister(0);
                if ($this->registers[0]->band($imm)->equals(0)) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return;

            // AND #imm,R0
            case 0xc900:
                $imm = getImm8($instruction)->u32();
                $this->disasm("AND", ["#H'{$imm->hex()}", "R0"]);
                $this->writeRegister(0, $this->registers[0]->band($imm));
                return;

            // OR #imm,R0
            case 0xcb00:
                $imm = getImm8($instruction)->u32();
                $this->disasm("OR", ["#H'{$imm->hex()}", "R0"]);
                $this->writeRegister(0, $this->registers[0]->bor($imm));
                return;
        }

        // f0ff
        switch ($instruction & 0xf0ff) {
            // STS MACL,<REG_N>
            case 0x001a:
                $n = getN($instruction);
                $this->disasm("STS", ["MACL", "R$n"]);
                $this->writeRegister($n, U32::of($this->macl));
                return;

            // BRAF <REG_N>
            case 0x0023:
                $n = getN($instruction);
                $this->disasm("BRAF", ["R$n"]);
                $newpc = $this->registers[$n]->value + $this->pc + 2;

                //WARN : r[n] can change here
                $this->executeDelaySlot();

                $this->pc = $newpc;
                return;

            // MOVT <REG_N>
            case 0x0029:
                $n = getN($instruction);
                $this->disasm("MOVT", ["R$n"]);
                $this->writeRegister($n, U32::of($this->srT));
                return;

            // STS FPUL,<REG_N>
            case 0x005a:
                $n = getN($instruction);
                $this->disasm("STS", ["FPUL","R$n"]);
                $this->writeRegister($n, U32::of($this->fpul));
                return;

            case 0x002a:
                $n = getN($instruction);
                $this->disasm("STS", ["PR","R$n"]);
                $this->writeRegister($n, U32::of($this->pr));
                return;

            // SHLL <REG_N>
            case 0x4000:
                $n = getN($instruction);
                $this->disasm("SHLL", ["R$n"]);
                $this->srT = $this->registers[$n]->shiftRight(31)->value;
                $this->writeRegister($n, $this->registers[$n]->shiftLeft());
                return;

            // SHLR <REG_N>
            case 0x4001:
                $n = getN($instruction);
                $this->disasm("SHLR", ["R$n"]);
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $this->writeRegister($n, $this->registers[$n]->shiftRight());
                return;

            // SHLL2 Rn
            case 0x4008:
                $n = getN($instruction);
                $this->disasm("SHLL2", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftLeft(2));
                return;

            // SHLR2 Rn
            case 0x4009:
                $n = getN($instruction);
                $this->disasm("SHLR2", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftRight(2));
                return;

            // JSR
            case 0x400b:
                $n = getN($instruction);
                $this->disasm("JSR", ["@R$n"]);

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
                $this->disasm("CMP/PZ", ["R$n"]);
                $this->logRegister($n);
                if ($this->registers[$n]->signedValue() >= 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return;

            // CMP/PL <REG_N>
            case 0x4015:
                $n = getN($instruction);
                $this->disasm("CMP/PL", ["R$n"]);
                $this->logRegister($n);
                if ($this->registers[$n]->signedValue() > 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                return;

            // SHLL8  Rn;
            case 0x4018:
                $n = getN($instruction);
                $this->disasm("SHLL8", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftLeft(8));
                return;

            // SHLR8  Rn;
            case 0x4019:
                $n = getN($instruction);
                $this->disasm("SHLR8", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftRight(8));
                return;

            // SHAR  Rn;
            case 0x4021:
                $n = getN($instruction);
                $this->disasm("SHAR", ["R$n"]);
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $sign = $this->registers[$n]->band(0x80000000);
                $this->writeRegister($n, $this->registers[$n]->shiftRight()->bor($sign));
                return;

            // STS.L PR,@-<REG_N>
            case 0x4022:
                $n = getN($instruction);
                $this->disasm("STS.L", ["PR", "@-R$n"]);
                $address = $this->registers[$n]->sub(4);
                $this->memory->writeUInt32($address->value, U32::of($this->pr));
                $this->writeRegister($n, $address);
                return;

            // LDS.L @<REG_N>+,PR
            case 0x4026:
                $n = getN($instruction);
                $this->disasm("LDS.L", ["@R$n+", "PR"]);
                // TODO: Use read proxy?
                $this->pr = $this->memory->readUInt32($this->registers[$n]->value)->value;
                $this->registers[$n] = $this->registers[$n]->add(4);
                return;

            // SHLL16 Rn;
            case 0x4028:
                $n = getN($instruction);
                $this->disasm("SHLL16", ["R$n"]);
                $this->writeRegister($n, $this->registers[$n]->shiftLeft(16));
                return;

            // JMP
            case 0x402b:
                $n = getN($instruction);
                $this->disasm("JMP", ["R$n"]);
                $newpc = $this->registers[$n]->value;
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

                $this->logInfo("PC = 0x" . dechex($newpc) . "");

                $this->pc = $newpc;
                return;

            // LDS <REG_M>,FPUL
            case 0x405a:
                $n = getN($instruction);
                $this->disasm("LDS", ["R$n", "FPUL"]);
                $this->fpul = $this->registers[$n]->value;
                $hex = dechex($this->fpul);
                $this->addLog("FPUL=H'$hex");
                return;

            // FSTS        FPUL,<FREG_N>
            case 0xf00d:
                $n = getN($instruction);
                $this->disasm("FSTS", ["FPUL", "FR$n"]);
                $this->writeFloatRegister($n, unpack('f', pack('L', $this->fpul))[1]);
                return;

            // FLOAT       FPUL,<FREG_N>
            case 0xf02d:
                $n = getN($instruction);
                $this->disasm("FLOAT", ["FPUL", "FR$n"]);
                $this->writeFloatRegister($n, (float) $this->fpul);
                return;

            // FTRC <FREG_N>,FPUL
            case 0xf03d:
                $n = getN($instruction);
                $this->disasm("FTRC", ["FR$n", "FPUL"]);
                $this->fpul = ((int) $this->fregisters[$n]) & 0xffffffff;
                return;

            // FNEG <FREG_N>
            case 0xf04d:
                $n = getN($instruction);
                // if (fpscr.PR ==0)
                $this->disasm("FNEG", ["FR$n"]);
                $this->writeFloatRegister($n, -$this->fregisters[$n]);
                // else
                return;

            // FLDI0
            case 0xf08d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }
                    
                $n = getN($instruction);
                $this->disasm("FLDI0", ["FR$n"]);
                $this->writeFloatRegister($n, 0.0);
                return;

            // FLDI1
            case 0xf09d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }
                    
                $n = getN($instruction);
                $this->disasm("FLDI1", ["FR$n"]);
                $this->writeFloatRegister($n, 1.0);
                return;
        }

        // f000
        switch ($instruction & 0xf000) {
            // BRA <bdisp12>
            case 0xa000:
                $newpc = branchTargetS12($instruction, $this->pc);
                $newpcHex = dechex($newpc);
                $this->disasm("BRA", ["H'$newpcHex"]);
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
                $this->disasm("BSR", ["H'$newpcHex"]);
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
                $disp = getImm8($instruction)->u32()->shiftLeft(2)->value;
                $this->disasm("MOV.L", ["@($disp,PC)","R$n"]);

                $addr = (($this->pc + 2) & 0xFFFFFFFC);
                $data = $this->readUInt32($addr, $disp);
                // TODO: Should this be done to every read or just disp + PC (Literal Pool)
                if ($relocation = $this->getRelocationAt($addr + $disp)) {
                    // TODO: If rellocation has been initialized in test, set
                    // rellocation address instead.
                    $data = $relocation;
                }
                $this->writeRegister($n, $data);
                return;
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

        $this->addLog("R$n=H'{$value->shortHex()}");
    }

    public function setFloatRegister(int $n, float $value): void
    {
        $this->fregisters[$n] = $value;
    }

    private function writeFloatRegister(int $n, float $value): void
    {
        $this->fregisters[$n] = $value;

        $this->addLog("FR$n=$value");
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
            $this->writeRegister(0, $this->registers[1]->mod($this->registers[0]));
            return;
        }

        if ($name === '__divls') {
            $this->writeRegister(0, $this->registers[1]->div($this->registers[0]));
            return;
        }

        /** @var AbstractExpectation */
        $expectation = array_shift($this->pendingExpectations);

        if (!($expectation instanceof CallExpectation)) {
            throw new ExpectationException("Unexpected function call to $readableName at " . dechex($this->pc));
        }

        if ($name !== $expectation->name) {
            throw new ExpectationException("Unexpected call to $readableName at " . dechex($this->pc) . ", expecting $expectation->name");
        }

        if ($expectation->parameters) {
            // TODO: Handle other calling convetions?
            $convention = new DefaultCallingConvention();

            foreach ($expectation->parameters as $expected) {
                if ($expected instanceof WildcardArgument) {
                    // FIXME: Allow wildcard float arguments?
                    $convention->getNextArgumentStorage(ArgumentType::General);
                    continue;
                }

                // TODO: No tests are using this
                // if ($expected instanceof LocalArgument) {
                //     // FIXME: Why increment here!?
                //     $args++;

                //     if ($args <= 4) {
                //         $register = $args + 4 - 1;
                //         $actual = $this->registers[$register];

                //         if ($actual < $this->registers[15]) {
                //             throw new ExpectationException("Unexpected local argument for $readableName in r$register. $actual is not in the stack");
                //         }

                //         continue;
                //     }

                //     throw new \Exception("Stack arguments stored in stack are not supported at the moment", 1);
                // }

                if (is_int($expected)) {
                    $storage = $convention->getNextArgumentStorage(ArgumentType::General);
                    $expected &= 0xffffffff;

                    if ($storage instanceof GeneralRegister) {
                        $register = $storage->index();
                        $actual = $this->registers[$register];
                        $actualHex = dechex($actual->value);
                        $expectedHex = dechex($expected);
                        if (!$actual->equals($expected)) {
                            throw new ExpectationException("Unexpected argument for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)");
                        }

                        continue;
                    }

                    if ($storage instanceof StackOffset) { 
                        $offset = $storage->offset;

                        $address = $this->registers[15]->value + $offset;
                        $actual = $this->memory->readUInt32($address);

                        if (!$actual->equals($expected)) {
                            throw new ExpectationException("Unexpected argument for $readableName in stack offset $offset ($address). Expected $expected, got $actual");
                        }

                        continue;
                    }

                    throw new \Exception("Unexpected argument storage type", 1);
                }

                if (is_float($expected)) {
                    $storage = $convention->getNextArgumentStorage(ArgumentType::FloatingPoint);

                    if ($storage instanceof FloatingPointRegister) {
                        $register = $storage->index();
                        $actual = $this->fregisters[$register];
                        $actualDecRepresentation = unpack('L', pack('f', $actual))[1];
                        $expectedDecRepresentation = unpack('L', pack('f', $expected))[1];
                        if ($actualDecRepresentation !== $expectedDecRepresentation) {
                            throw new ExpectationException("Unexpected float argument for $readableName in fr$register. Expected $expected, got $actual");
                        }
    
                        continue;
                    }

                    if ($storage instanceof StackOffset) {
                        $offset = $storage->offset;

                        $address = $this->registers[15]->value + $offset;
                        $actualDecRepresentation = $this->memory->readUInt32($address);
                        $actual = unpack('f', pack('L', $actualDecRepresentation))[1];
                        $expectedDecRepresentation = unpack('L', pack('f', $expected))[1];

                        if ($actualDecRepresentation !== $expectedDecRepresentation) {
                            throw new ExpectationException("Unexpected float argument for $readableName in stack offset $offset ($address). Expected $expected, got $actual");
                        }
    
                        continue;
                    }

                    throw new \Exception("Unexpected argument storage type", 1);
                }

                if (is_string($expected)) {
                    $storage = $convention->getNextArgumentStorage(ArgumentType::General);

                    if ($storage instanceof GeneralRegister) {
                        $register = $storage->index();
                        $address = $this->registers[$register];

                        $actual = $this->memory->readString($address->value);
                        if ($actual !== $expected) {
                            $actualHex = bin2hex($actual);
                            $expectedHex = bin2hex($expected);
                            throw new ExpectationException("Unexpected char* argument for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)");
                        }

                        continue;
                    }

                    throw new \Exception("String literal stack arguments are not supported at the moment", 1);
                }

                throw new \Exception("Unexpected argument type", 1);
            }
        }

        // TODO: Temporary hack to modify write during runtime
        if ($expectation->callback) {
            $callback = \Closure::bind($expectation->callback, $this, $this);
            $callback($expectation->parameters);
        }

        if ($expectation->return !== null) {
            $this->writeRegister(0, U32::of($expectation->return & 0xffffffff));
        }

        $this->fulfilled("Called " . $readableName . '(0x'. dechex($target) . ")");
    }

    public function hexdump(): void
    {
        echo "PC: " . dechex($this->pc) . "\n";
        // print_r($this->registers);

        // return;

        // TODO: Unhardcode memory size
        for ($i=0x1660; $i < 0x1660 + 0x400; $i++) {
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

    public function getSymbolNameAt(int $address): ?string
    {
        if ($relocation = $this->getResolutionAt($address)) {
            return $relocation->name;
        }

        if ($export = $this->object->unit->findExportedAddress($address)) {
            return $export->name;
        }

        return null;
    }

    public function enableDisasm(): void
    {
        $this->shouldDisasm = true;
    }

    private function log(mixed $str): void
    {
        if ($this->shouldDisasm) {
            echo $str;
        }
    }

    /**
     * @param string $instruction
     * @param string[] $opcode
     */
    private function disasm(string $instruction, array $operands = []): void
    {
        if (!$this->shouldDisasm) {
            return;
        }

        $fg = 'default';

        if (preg_match('/^(B.*|J.*|RTS)/', $instruction)) {
            $fg = 'red';
        } elseif (preg_match('/^(TST|CMP.*|FCMP.*)$/', $instruction)) {
            $fg = 'yellow';
        } elseif (preg_match('/^(.*\.(L|W|B|S)|MOVA)$/', $instruction, $matches)) {
            $fg = 'white';
        } elseif ($instruction === 'NOP') {
            $fg = 'gray';
        }

        $addr = str_pad(dechex($this->disasmPc), 6, '0', STR_PAD_LEFT);

        $line = "<fg=gray>0x$addr " . $this->memory->readUInt16($this->disasmPc)->hex() . "</> ";
        $line .= $this->inDelaySlot ? '_' : ' ';

        $instruction = str_pad($instruction, 8, ' ', STR_PAD_RIGHT);
        $line .= "<fg=$fg>$instruction</>";

        $styleOperand = function ($operand) {
            $fg = 'default';

            // FIXME
            $operand = trim($operand);

            $prefix = '';
            $suffix = '';
            if (preg_match('/^([@+-]*)(F?R\d+|PR|PC|MACL|FPUL)([+-]*)$/', $operand, $matches)) {
                $prefix = $matches[1];
                $operand = $matches[2];
                $suffix = $matches[3];
                $fg = 'bright-magenta';

                if (in_array($operand, ['PR', 'PC', 'MACL', 'FPUL'])) {
                    $fg = 'magenta';
                }
            } else if (preg_match('/^#-?(:?H\')?[0-9A-Za-z]+$/', $operand, $matches)) {
                $fg = 'bright-green';
            }

            return "$prefix<fg=$fg>$operand</>$suffix";
        };

        $operands = array_map(function ($operand) use ($styleOperand) {
            if (str_starts_with($operand, '@(')) {
                $operands = explode(',', substr($operand, 2, -1));
                $operands = join('<fg=default>,</>', array_map($styleOperand, $operands));
                return "@<fg=default>(</>$operands<fg=default>)</>";
            }

            return $styleOperand($operand);
        }, $operands);

        $line .= ' ' . implode('<fg=default>,</>', $operands);

        if ($this->inDelaySlot) {
            $this->delaySlotDisasm = $line;
        } else {
            $this->disasm = $line;
        }
    }

    private function fulfilled(string $message): void {
        $this->handleMessage("<fg=green>✔ Fulfilled: $message</>");
    }

    private function logInfo(string $str): void {
        $this->handleMessage("<fg=blue>$str</>");
    }

    private function addLog(string $str): void {
        if ($this->inDelaySlot) {
            $this->delaySlotRegisterLog[] = $str;
            return;
        }

        $this->registerLog[] = $str;
    }

    private function logRegister(int $index): void
    {
        $value = $this->registers[$index];
        $this->addLog("R$index:H'{$value->shortHex()}");
    }

    private function logRegisters(array $registers): void
    {
        foreach ($registers as $index) {
            $this->logRegister($index);
        }
    }

    private function logFloatRegister(int $index): void
    {
        $value = $this->fregisters[$index];
        $this->addLog("FR$index:$value");
    }

    private function logFloatRegisters(array $registers): void
    {
        foreach ($registers as $index) {
            $this->logFloatRegister($index);
        }
    }

    /**
     * Either output message or store it for later when in disasm mode
     */
    private function handleMessage(string $message): void
    {
        if ($this->inDelaySlot) {
            $this->delaySlotMessages[] = $message;
            return;
        }

        $this->messages[] = $message;
    }

    private function outputMessages(): void
    {
        $addLog = function ($line, $log) {
            $len = strlen(strip_tags($line));
            $padn = 40 - $len;

            if ($padn > 0) {
                $line .= str_repeat(' ', $padn);
            }

            $line .= '<fg=gray>' . implode(' ', $log) . '</>';

            return $line;
        };

        if ($this->disasm) {
            $disasm = $addLog($this->disasm, $this->registerLog);
            $this->output->writeln($disasm);
        }

        if ($this->delaySlotDisasm) {
            $disasm = $addLog($this->delaySlotDisasm, $this->delaySlotRegisterLog);
            $this->output->writeln($disasm);
        }

        foreach ($this->delaySlotMessages as $message) {
            $this->output->writeln($message);
        }

        foreach ($this->messages as $message) {
            $this->output->writeln($message);
        }

        $this->disasm = null;
        $this->delaySlotDisasm = null;
        $this->messages = [];
        $this->delaySlotMessages = [];
        $this->registerLog = [];
        $this->delaySlotRegisterLog = [];
    }

    protected function readUInt(int $addr, int $offset, int $size): U8|U16|U32
    {
        $displacedAddr = $addr + $offset;

        $readableAddress = '0x' . dechex($displacedAddr);
        if ($symbol = $this->getSymbolNameAt($displacedAddr)) {
            $readableAddress = "$symbol($readableAddress)";
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
                throw new ExpectationException("Unexpected read size $size from $readableAddress. Expecting size $expectation->size");
            }

            if (!$value->equals($expectation->value)) {
                throw new ExpectationException("Unexpected read of $readableValue from $readableAddress. Expecting value $readableExpected");
            }

            $this->fulfilled("Read $readableExpected from $readableAddress");
            array_shift($this->pendingExpectations);
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

        // TODO: I really don't like how we need to keep checking for the expectation type here.

        // Stack write
        if ($address >= $this->registers[15]->value) {
            // Unexpected stack writes are allowed
            if (!($expectation instanceof WriteExpectation
                    || $expectation instanceof StringWriteExpectation)
                || $expectation->address !== $address
            ) {
                $this->logInfo("Allowed stack write of $readableValue to $readableAddress");
                return;
            }
        } else if (!($expectation instanceof WriteExpectation || $expectation instanceof StringWriteExpectation)) {
            throw new ExpectationException("Unexpected write of " . $readableValue . " to " . $readableAddress . "\n");
        }

        if ($symbol = $this->getSymbolNameAt($address)) {
            $readableAddress = "$symbol($readableAddress)";
        }

        $readableExpectedAddress = '0x' . dechex($expectation->address);
        if ($symbol = $this->getSymbolNameAt($expectation->address)) {
            $readableExpectedAddress = "$symbol($readableExpectedAddress)";
        }

        // Handle char* writes
        if (is_string($expectation->value)) {
            if (!($expectation instanceof StringWriteExpectation)) {
                throw new ExpectationException("Unexpected char* write of $readableValue to $readableAddress, expecting int write of $readableExpectedAddress");
            }

            if ($value::BIT_COUNT !== 32) {
                throw new ExpectationException("Unexpected non 32bit char* write of $readableValue to $readableAddress");
            }

            $actual = $this->memory->readString($value->value);
            $readableValue = $actual . ' (' . bin2hex($actual) . ')';
            $readableExpectedValue = $expectation->value . ' (' . bin2hex($expectation->value) . ')';

            if ($expectation->address !== $address) {
                throw new ExpectationException("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress");
            }
            
            if ($actual !== $expectation->value) {
                throw new ExpectationException("Unexpected char* write value $readableValue to $readableAddress, expecting $readableExpectedValue");
            }

            $this->fulfilled("Wrote string $readableValue to $readableAddress");
        }
        // Hanlde int writes
        else {
            if (!($expectation instanceof WriteExpectation)) {
                throw new ExpectationException("Unexpected int write of $readableValue to $readableAddress, expecting char* write of $readableExpectedAddress");
            }

            if ($value::BIT_COUNT !== $expectation->size) {
                throw new ExpectationException("Unexpected " . $value::BIT_COUNT . " bit write of $readableValue to $readableAddress, expecting $expectation->size bit write");
            }

            $readableExpectedValue = $expectation->value . '(0x' . dechex($expectation->value) . ')';
            if ($expectation->address !== $address) {
                throw new ExpectationException("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress");
            }

            if ($value->lessThan(0)) {
                throw new ExpectationException("Unexpected negative write value $readableValue to $readableAddress");
            }

            if (!$value->equals($expectation->value)) {
                throw new ExpectationException("Unexpected write value $readableValue to $readableAddress, expecting value $readableExpectedValue");
            }

            $this->fulfilled("Wrote $readableValue to $readableAddress");
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
