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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;

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

    private bool $shouldDisasm = false;

    /** @var Relocation[] */
    private array $unresolvedRelocations = [];

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
                $this->registers[4 + $i] = U32::of($parameter);
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
                    // TODO: Use SInt value object
                    $this->memory->writeUInt8($initialization->address, U8::of($initialization->value & U8::MAX_VALUE));
                    break;

                case U16::BIT_COUNT:
                    // TODO: Use SInt value object
                    $this->memory->writeUInt16($initialization->address, U16::of($initialization->value & U16::MAX_VALUE));
                    break;

                case U32::BIT_COUNT:
                    // TODO: Use SInt value object
                    $this->memory->writeUInt32($initialization->address, U32::of($initialization->value & U32::MAX_VALUE));
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
            throw new ExpectationException("Unexpected return value $actualReturn, expecting $expectedReturn", 1);
        }

        // TODO: returns and float returns are mutually exclusive
        if ($this->entry->floatReturn !== null) {
            $expectedFloatReturn = $this->entry->floatReturn;
            $actualFloatReturn = $this->fregisters[0];
            $expectedDecRepresentation = unpack('L', pack('f', $expectedFloatReturn))[1];
            $actualDecRepresentation = unpack('L', pack('f', $actualFloatReturn))[1];

            if ($actualDecRepresentation !== $expectedDecRepresentation) {
                throw new ExpectationException("Unexpected return value $actualFloatReturn, expecting $expectedFloatReturn", 1);
            }
        }

        $count = count($this->expectations);
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
                $disp = getImm4($instruction)->u32()->shiftLeft(2);
                $this->disasm("MOV.L", ["R$m", "@($disp,R$n)"]);
                $this->writeUInt32($this->registers[$n]->value, $disp->value, $this->registers[$m]);
                return;

            // MOV.L @(<disp>,<REG_M>),<REG_N>
            case 0x5000:
                [$n, $m] = getNM($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft(2);
                $this->disasm("MOV.L", ["@($disp,R$m)","R$n"]);
                $this->setRegister($n, $this->readUInt32($this->registers[$m]->value, $disp->value));
                return;

            // ADD #imm,Rn
            case 0x7000:
                $n = getN($instruction);
                $imm = getImm8($instruction);

                // TODO: Use SInt value object
                $this->setRegister($n, $this->registers[$n]->add($imm->extend32(), allowOverflow: true));

                $this->disasm("ADD", ["#$imm","R$n"]);
                return;

            // MOV.W @(<disp>,PC),<REG_N>
            case 0x9000:
                $n = getN($instruction);
                $disp = getImm8($instruction)->u32()->shiftLeft();
                $this->setRegister($n, $this->readUInt16($this->pc + 2, $disp->value)->extend32());
                $this->disasm("MOV.W", ["@($disp,PC)","R$n"]);
                return;

            // MOV #imm,Rn
            case 0xe000:
                $imm = getImm8($instruction);
                $n = getN($instruction);
                $this->setRegister($n, $imm->extend32());
                $this->disasm("MOV", ["#$imm","R$n"]);
                return;
        }

        switch ($instruction & 0xf1ff) {
            // TODO
        }

        switch ($instruction & 0xf00f) {
            // MOV.L <REG_M>, @(R0,<REG_N>)
            case 0x0006:
                [$n, $m] = getNM($instruction);
                // TODO: Is R0 always the offset?
                $this->writeUInt32($this->registers[$n]->value, $this->registers[0]->value, $this->registers[$m]);
                $this->disasm("MOV.L", ["R$m", "@(R0,R$n)"]);
                return;

            // MUL.L <REG_M>,<REG_N>
            case 0x0007:
                [$n, $m] = getNM($instruction);
                $result = $this->registers[$n]->mul($this->registers[$m]);
                $this->macl = $result->value;
                $this->addRegisterLog("MACL={$result->readable()}");
                $this->disasm("MUL.L", ["R$m","R$n"]);
                return;

            // MOV.B @(R0,<REG_M>),<REG_N>
            case 0x000c:
                [$n, $m] = getNM($instruction);
                $value = $this->readUInt8($this->registers[0]->value, $this->registers[$m]->value);
                $this->setRegister($n, $value->extend32());
                $this->disasm("MOV.B", ["@(R0, R$m)","R$n"]);
                return;

            // MOV.W @(R0,<REG_M>),<REG_N>
            case 0x000d:
                [$n, $m] = getNM($instruction);
                $value = $this->readUInt16($this->registers[0]->value, $this->registers[$m]->value);
                $this->setRegister($n, $value->extend32());
                $this->disasm("MOV.W", ["@(R0,R$m)","R$n"]);
                return;

            // MOV.L @(R0,<REG_M>),<REG_N>
            case 0x000e:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->readUInt32($this->registers[0]->value, $this->registers[$m]->value));
                $this->disasm("MOV.L", ["@(R0,R$m)","R$n"]);
                return;

            // MOV.B Rm,@Rn
            case 0x2000:
                [$n, $m] = getNM($instruction);
                $this->disasm("MOV.B", ["R$m", "@R$n"]);

                $addr = $this->registers[$n];
                $this->writeUInt8($this->registers[$n]->value, 0, $this->registers[$m]->trunc8());
                return;

            // MOV.L Rm,@Rn
            case 0x2002:
                [$n, $m] = getNM($instruction);
                $addr = $this->registers[$n];
                $this->writeUInt32($this->registers[$n]->value, 0, $this->registers[$m]);
                $this->disasm("MOV.L", ["R$m", "@R$n"]);
                return;

            // MOV.L Rm,@-Rn
            case 0x2006:
                $n = getN($instruction);
                $m = getM($instruction);

                $addr = $this->registers[$n]->value - 4;

                $this->memory->writeUInt32($addr, $this->registers[$m]);
                $this->setRegister($n, U32::of($addr));
                $this->disasm("MOV.L", ["R$m", "@-R$n"]);
                return;

            // TST Rm,Rn
            case 0x2008:
                [$n, $m] = getNM($instruction);

                if ($this->registers[$n]->band($this->registers[$m])->value !== 0) {
                    $this->srT = 0;
                } else {
                    $this->srT = 1;
                }

                $this->disasm("TST", ["R$m","R$n"]);
                return;

            // AND <REG_M>,<REG_N>
            case 0x2009:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->registers[$n]->band($this->registers[$m]));
                $this->disasm("AND", ["R$m","R$n"]);
                return;

            // OR Rm,Rn
            case 0x200b:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->registers[$n]->bor($this->registers[$m]));
                $this->disasm("OR", ["R$m","R$n"]);
                return;

            // CMP/EQ <REG_M>,<REG_N>
            case 0x3000:
                [$n, $m] = getNM($instruction);
                if ($this->registers[$n]->equals($this->registers[$m])) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }
                $this->disasm("CMP/EQ", ["R$m","R$n"]);
                return;

            // CMP/HS <REG_M>,<REG_N>
            case 0x3002:
                [$n, $m] = getNM($instruction);
                // TODO: Double check signed to unsigned convertion
                if ($this->registers[$n]->greaterThanOrEqual($this->registers[$m])) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                $this->disasm("CMP/HS", ["R$m","R$n"]);
                return;

            // CMP/GE <REG_M>,<REG_N>
            case 0x3003:
                [$n, $m] = getNM($instruction);
                // TODO: Create SInt value object
                if ($this->registers[$n]->signedValue() >= $this->registers[$m]->signedValue()) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                $this->disasm("CMP/GE", ["R$m","R$n"]);
                return;

            // CMP/GT <REG_M>,<REG_N>
            case 0x3007:
                [$n, $m] = getNM($instruction);
                if ($this->registers[$n]->greaterThan($this->registers[$m])) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                $this->disasm("CMP/GT", ["R$m","R$n"]);
                return;

            // SUB <REG_M>,<REG_N>
            case 0x3008:
                [$n, $m] = getNM($instruction);
                // TODO: Use SInt value object
                $result = U32::of(($this->registers[$n]->value - $this->registers[$m]->value) & 0xffffffff);
                $this->setRegister($n, $result);
                $this->disasm("SUB", ["R$m","R$n"]);
                return;

            // ADD Rm,Rn
            case 0x300c:
                [$n, $m] = getNM($instruction);
                
                // TODO: Use SInt value object
                $result = $this->registers[$n]->add($this->registers[$m], allowOverflow: true);
                $this->setRegister($n, $result);
                $this->disasm("ADD", ["R$m","R$n"]);
                return;

            // MOV.B @Rm,Rn
            case 0x6000:
                [$n, $m] = getNM($instruction);
                
                $this->setRegister($n, $this->readUInt8($this->registers[$m]->value)->extend32());
                $this->disasm("MOV.B", ["@R$m","R$n"]);
                return;

            // MOV.W @<REG_M>,<REG_N>
            case 0x6001:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->readUInt16($this->registers[$m]->value)->extend32());
                $this->disasm("MOV.W", ["@R$m","R$n"]);
                return;

            // MOV @Rm,Rn
            case 0x6002:
                [$n, $m] = getNM($instruction);

                $this->setRegister($n, $this->readUInt32($this->registers[$m]->value));
                $this->disasm("MOV", ["@R$m","R$n"]);
                return;

            // MOV Rm,Rn
            case 0x6003:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->registers[$m]);
                $this->disasm("MOV", ["R$m","R$n"]);
                return;

            // MOV @<REG_M>+,<REG_N>
            case 0x6006:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->readUInt32($this->registers[$m]->value));
                
                if ($n != $m) {
                    $this->registers[$m] = $this->registers[$m]->add(4);
                }
                
                $this->disasm("MOV", ["@R$m+","R$n"]);
                return;

            // NEG <REG_M>,<REG_N>
            case 0x600b:
                [$n, $m] = getNM($instruction);
                
                $this->setRegister($n, $this->registers[$m]->invert());
                $this->disasm("NEG", ["R$m","R$n"]);
                return;

            // EXTU.B <REG_M>,<REG_N>
            case 0x600c:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->registers[$m]->trunc8()->u32());
                $this->disasm("EXTU.B", ["R$m","R$n"]);
                return;

            // EXTS.B <REG_M>,<REG_N>
            case 0x600e:
                [$n, $m] = getNM($instruction);
                $this->setRegister($n, $this->registers[$m]->trunc8()->extend32());
                $this->disasm("EXTS.B", ["R$m","R$n"]);
                return;

            // FADD <FREG_M>,<FREG_N>
            case 0xf000:
                // if (fpscr.PR == 0)
                // {
                    [$n, $m] = GetNM($instruction);
                    $this->setFloatRegister($n, $this->fregisters[$n] + $this->fregisters[$m]);
                    $this->disasm("FADD", ["FR$m", "FR$n"]);
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
                    $this->setFloatRegister($n, $this->fregisters[$n] - $this->fregisters[$m]);
                    $this->disasm("FSUB", ["FR$m", "FR$n"]);
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
                    $this->setFloatRegister($n, $this->fregisters[$n] * $this->fregisters[$m]);
                    $this->disasm("FMUL", ["FR$m", "FR$n"]);
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
                    $this->setFloatRegister($n, $this->fregisters[$n] / $this->fregisters[$m]);
                    $this->disasm("FDIV", ["FR$m", "FR$n"]);
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

                    $this->disasm("FCMP/GT", ["FR$m", "FR$n"]);
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
                    
                    $value = $this->readUInt32($this->registers[$m]->value, $this->registers[0]->value)->value;
                    $this->setFloatRegister($n, unpack('f', pack('L', $value))[1]);
                    $this->disasm("FMOV.S", ["@(R0, R$m)", "FR$n"]);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@(R0,<REG_N>)
            case 0xf007:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->writeUInt32($this->registers[$n]->value, $this->registers[0]->value, U32::of($value));
                    $this->disasm("FMOV.S", ["FR$m", "@(R0,R$n)"]);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S @<REG_M>,<FREG_N>
            case 0xf008:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    
                    $value = $this->readUInt32($this->registers[$m]->value)->value;
                    $this->setFloatRegister($n, unpack('f', pack('L', $value))[1]);
                    $this->disasm("FMOV.S", ["@R$m", "FR$n"]);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S @<REG_M>+,<FREG_N>
            case 0xf009:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    
                    // TODO: Use read proxy?
                    $value = $this->readUInt32($this->registers[$m]->value)->value;
                    $this->setFloatRegister($n, unpack('f', pack('L', $value))[1]);
                    $this->registers[$m] = $this->registers[$m]->add(4);
                    $this->disasm("FMOV.S", ["@R$m+", "FR$n"]);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@<REG_N>
            case 0xf00a:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->writeUInt32($this->registers[$n]->value, 0, U32::of($value));
                    $this->disasm("FMOV.S", ["FR$m", "@R$n"]);
                // } else {
                    // ...
                // }
                return;

            // FMOV.S <FREG_M>,@-<REG_N>
            case 0xf00b:
                // if (fpscr.SZ == 0) {
                    [$n, $m] = getNM($instruction);
                    
                    $addr = $this->registers[$n]->sub(4);
                    
                    $value = unpack('L', pack('f', $this->fregisters[$m]))[1];
                    $this->memory->writeUInt32($addr->value, U32::of($value));
                    $this->setRegister($n, $addr);
                    $this->disasm("FMOV.S", ["FR$m", "@-R$n"]);
                // } else {
                    // ...
                // }
                return;

            // FMOV <FREG_M>,<FREG_N>
            case 0xf00c:
                // if (fpscr.SZ == 0)
                // {
                    [$n, $m] = getNM($instruction);
                    $this->setFloatRegister($n, $this->fregisters[$m]);
                    $this->disasm("FMOV", ["FR$m", "FR$n"]);
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
                    $this->setFloatRegister($n, $this->fregisters[$n] + $this->fregisters[0] * $this->fregisters[$m]);
                    $this->disasm("FMAC", ["FR0,FR$m,FR$n"]);
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
            // MOV.W R0,@(<disp>,<REG_M>)
            case 0x8100:
                $m = getN($instruction);
                $disp = getImm4($instruction)->u32()->shiftLeft()->value;
                $this->writeUInt16($this->registers[$m]->value, $disp, $this->registers[0]->trunc16());
                $this->disasm("MOV.W", ["R0", "@($disp, R$m)"]);
                return;

            // MOV.B @(<disp>, <REG_M>),R0
            case 0x8400:
                $m = getM($instruction);
                $disp = getImm4($instruction);
                $this->setRegister(0, $this->readUInt8($this->registers[$m]->value, $disp->value)->extend32());
                $this->disasm("MOV.B", ["@($disp, R$m)", "R0"]);
                return;

            // CMP/EQ #<imm>,R0
            case 0x8800:
                $imm = getSImm8($instruction) & 0xffffffff;
                if ($this->registers[0]->equals($imm)) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                $this->disasm("CMP/EQ", ["#$imm", "R0"]);
                return;

            // BT <bdisp8>
            case 0x8900:
                $target = branchTargetS8($instruction, $this->pc);
                if ($this->srT !== 0) {
                    $this->pc = $target;
                }
                $this->disasm("BT", ["H'" . dechex($target)]);
                return;

            // BF <bdisp8>
            case 0x8b00:
                $target = branchTargetS8($instruction, $this->pc);
                if ($this->srT === 0) {
                    $this->pc = $target;
                }
                $this->disasm("BF", ["H'" . dechex($target)]);
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
                $this->setRegister(
                    0,
                    U32::of(($this->pc + 2) & 0xfffffffc)->add($disp)
                );
                $this->disasm("MOVA", ["@($disp,PC)", "R0"]);
                return;

            // TST #imm,R0
            case 0xc800:
                $imm = getImm8($instruction)->u32();
                if ($this->registers[0]->band($imm)->equals(0)) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                $this->disasm("TST", ["#$imm", "R0"]);
                return;

            // AND #imm,R0
            case 0xc900:
                $imm = getImm8($instruction)->u32();
                $this->setRegister(0, $this->registers[0]->band($imm));
                $this->disasm("AND", ["#$imm", "R0"]);
                return;

            // OR #imm,R0
            case 0xcb00:
                $imm = getImm8($instruction)->u32();
                $this->setRegister(0, $this->registers[0]->bor($imm));
                $this->disasm("OR", ["#$imm", "R0"]);
                return;
        }

        // f0ff
        switch ($instruction & 0xf0ff) {
            // STS MACL,<REG_N>
            case 0x001a:
                $n = getN($instruction);
                $this->setRegister($n, U32::of($this->macl));
                $this->disasm("STS", ["MACL", "R$n"]);
                return;

            // BRAF <REG_N>
            case 0x0023:
                $n = getN($instruction);
                $newpc = $this->registers[$n]->value + $this->pc + 2;
                $this->disasm("BRAF", ["R$n"]);

                //WARN : r[n] can change here
                $this->executeDelaySlot();

                $this->pc = $newpc;
                return;

            // MOVT <REG_N>
            case 0x0029:
                $n = getN($instruction);
                $this->setRegister($n, U32::of($this->srT));
                $this->disasm("MOVT", ["R$n"]);
                return;

            // STS FPUL,<REG_N>
            case 0x005a:
                $n = getN($instruction);
                $this->setRegister($n, U32::of($this->fpul));
                $this->disasm("STS", ["FPUL","R$n"]);
                return;

            case 0x002a:
                $n = getN($instruction);
                $this->setRegister($n, U32::of($this->pr));
                $this->disasm("STS", ["PR","R$n"]);
                return;

            // SHLL <REG_N>
            case 0x4000:
                $n = getN($instruction);
                $this->srT = $this->registers[$n]->shiftRight(31)->value;
                $this->setRegister($n, $this->registers[$n]->shiftLeft());
                $this->disasm("SHLL", ["R$n"]);
                return;

            // SHLR <REG_N>
            case 0x4001:
                $n = getN($instruction);
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $this->setRegister($n, $this->registers[$n]->shiftRight());
                $this->disasm("SHLR", ["R$n"]);
                return;

            // SHLL2  Rn;
            case 0x4008:
                $n = getN($instruction);
                $this->setRegister($n, $this->registers[$n]->shiftLeft(2));
                $this->disasm("SHLL2", ["R$n"]);
                return;

            // JSR
            case 0x400b:
                $n = getN($instruction);
                
                $newpr = $this->pc + 2;   //return after delayslot
                $newpc = $this->registers[$n]->value;

                $this->disasm("JSR", ["@R$n"]);

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
                if ($this->registers[$n]->signedValue() >= 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                $this->disasm("CMP/PZ", ["R$n"]);
                return;

            // CMP/PL <REG_N>
            case 0x4015:
                $n = getN($instruction);

                if ($this->registers[$n]->signedValue() > 0) {
                    $this->srT = 1;
                } else {
                    $this->srT = 0;
                }

                $this->disasm("CMP/PL", ["R$n"]);
                return;

            // SHLL8  Rn;
            case 0x4018:
                $n = getN($instruction);
                $this->setRegister($n, $this->registers[$n]->shiftLeft(8));
                $this->disasm("SHLL8", ["R$n"]);
                return;

            // SHAR  Rn;
            case 0x4021:
                $n = getN($instruction);
                $this->srT = $this->registers[$n]->band(0x1)->value;
                $sign = $this->registers[$n]->band(0x80000000);
                $this->setRegister($n, $this->registers[$n]->shiftRight()->bor($sign));
                $this->disasm("SHAR", ["R$n"]);
                return;

            // STS.L PR,@-<REG_N>
            case 0x4022:
                $n = getN($instruction);
                $address = $this->registers[$n]->sub(4);
                $this->memory->writeUInt32($address->value, U32::of($this->pr));
                $this->setRegister($n, $address);
                $this->disasm("STS.L", ["PR", "@-R$n"]);
                return;

            // LDS.L @<REG_N>+,PR
            case 0x4026:
                $n = getN($instruction);
                // TODO: Use read proxy?
                $this->pr = $this->memory->readUInt32($this->registers[$n]->value)->value;
                $this->registers[$n] = $this->registers[$n]->add(4);
                $this->disasm("LDS.L", ["@R$n+", "PR"]);
                return;

            // SHLL16 Rn;
            case 0x4028:
                $n = getN($instruction);
                $this->setRegister($n, $this->registers[$n]->shiftLeft(16));
                $this->disasm("SHLL16", ["R$n"]);
                return;

            // JMP
            case 0x402b:
                $n = getN($instruction);
                $newpc = $this->registers[$n]->value;
                $this->disasm("JMP", ["R$n"]);
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
                $this->fpul = $this->registers[$n]->value;
                $hex = dechex($this->fpul);
                $this->addRegisterLog("FPUL=H'$hex");
                $this->disasm("LDS", ["R$n", "FPUL"]);
                return;

            // FSTS        FPUL,<FREG_N>
            case 0xf00d:
                $n = getN($instruction);
                $this->setFloatRegister($n, unpack('f', pack('L', $this->fpul))[1]);
                $this->disasm("FSTS", ["FPUL", "FR$n"]);
                return;

            // FLOAT       FPUL,<FREG_N>
            case 0xf02d:
                $n = getN($instruction);
                $this->setFloatRegister($n, (float) $this->fpul);
                $this->disasm("FLOAT", ["FPUL", "FR$n"]);
                return;

            // FTRC <FREG_N>,FPUL
            case 0xf03d:
                $n = getN($instruction);
                $this->fpul = ((int) $this->fregisters[$n]) & 0xffffffff;
                $this->disasm("FTRC", ["FR$n", "FPUL"]);
                return;

            // FNEG <FREG_N>
            case 0xf04d:
                $n = getN($instruction);
                // if (fpscr.PR ==0)
                $this->setFloatRegister($n, -$this->fregisters[$n]);
                $this->disasm("FNEG", ["FR$n"]);
                // else
                return;

            // FLDI0
            case 0xf08d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }
                    
                $n = getN($instruction);
                $this->setFloatRegister($n, 0.0);
                $this->disasm("FLDI0", ["FR$n"]);
                return;

            // FLDI1
            case 0xf09d:
                // TODO
                // if (fpscr.PR!=0) {
                //     return;
                // }
                    
                $n = getN($instruction);
                $this->setFloatRegister($n, 1.0);
                $this->disasm("FLDI1", ["FR$n"]);
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
                $disp = getImm8($instruction)->u32()->mul(4);

                $addr = (($this->pc + 2) & 0xFFFFFFFC);

                $data = $this->readUInt32($addr, $disp->value);
                
                // TODO: Should this be done to every read or just disp + PC (Literal Pool)
                if ($relocation = $this->getRelocationAt($addr + $disp->value)) {
                    // TODO: If rellocation has been initialized in test, set
                    // rellocation address instead.
                    $data = $relocation;
                }

                $this->setRegister($n, $data);
                $this->disasm("MOV.L", ["@($disp,PC)","R$n"]);
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

        $this->addRegisterLog("R$n=H'{$value->shortHex()}");
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

        $this->addRegisterLog("FR$n=$value");
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
            throw new ExpectationException("Unexpected function call to $readableName at " . dechex($this->pc));
        }

        if ($name !== $expectation->name) {
            throw new ExpectationException("Unexpected call to $readableName at " . dechex($this->pc) . ", expecting $expectation->name", 1);
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
                            throw new ExpectationException("Unexpected local argument for $readableName in r$register. $actual is not in the stack", 1);
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
                            throw new ExpectationException("Unexpected parameter for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)", 1);
                        }

                        continue;
                    }

                    $offset = $stackOffset * 4;

                    $address = $this->registers[15]->value + $offset;
                    $actual = $this->memory->readUInt32($address);

                    if (!$actual->equals($expected)) {
                        throw new ExpectationException("Unexpected parameter in stack offset $offset ($address). Expected $expected, got $actual", 1);
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
                            throw new ExpectationException("Unexpected float parameter for $readableName in fr$register. Expected $expected, got $actual", 1);
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
                            throw new ExpectationException("Unexpected char* argument for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)", 1);
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
            $this->setRegister(0, U32::of($expectation->return & 0xffffffff));
        }

        $this->fulfilled("Called " . $readableName . '(0x'. dechex($target) . ")");
    }

    public function hexdump(): void
    {
        echo "PC: " . dechex($this->pc) . "\n";
        // print_r($this->registers);

        return;

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
            } else if (preg_match('/^#(:?H\')?[0-9A-Za-z]+$/', $operand, $matches)) {
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

    private function addRegisterLog(string $str): void {
        if ($this->inDelaySlot) {
            $this->delaySlotRegisterLog[] = $str;
            return;
        }

        $this->registerLog[] = $str;
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
                throw new ExpectationException("Unexpected read size $size from $readableAddress. Expecting size $expectation->size", 1);
            }

            if (!$value->equals($expectation->value)) {
                throw new ExpectationException("Unexpected read of $readableValue from $readableAddress. Expecting value $readableExpected", 1);
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

        // Stack writes are allowed
        // TODO: Allow user to define allowed writes
        if ($address >= $this->registers[15]->value) {
            $this->logInfo("Allowed stack write of $readableValue to $readableAddress");

            // TODO: Fix code flow
            // Should stack writes be disallowed?
            if ($expectation instanceof WriteExpectation && $expectation->address === $address) {
                //array_shift($this->pendingExpectations);
                //$this->fulfilled("WriteExpectation fulfilled: Wrote $readableValue to $readableAddress");
                throw new \Exception("Unimplemented stack write expectation handling", 1);
            }
            return;
        }

        if ($symbol = $this->getSymbolNameAt($address)) {
            $readableAddress = "$symbol($readableAddress)";
        }

        if (!($expectation instanceof WriteExpectation || $expectation instanceof StringWriteExpectation)) {
            throw new ExpectationException("Unexpected write of " . $readableValue . " to " . $readableAddress . "\n", 1);
        }

        $readableExpectedAddress = '0x' . dechex($expectation->address);

        if ($symbol = $this->getSymbolNameAt($expectation->address)) {
            $readableExpectedAddress = "$symbol($readableExpectedAddress)";
        }

        // Handle char* writes
        if (is_string($expectation->value)) {
            if (!($expectation instanceof StringWriteExpectation)) {
                throw new ExpectationException("Unexpected char* write of $readableValue to $readableAddress, expecting int write of $readableExpectedAddress", 1);
            }

            if ($value::BIT_COUNT !== 32) {
                throw new ExpectationException("Unexpected non 32bit char* write of $readableValue to $readableAddress", 1);
            }

            $actual = $this->memory->readString($value->value);
            $readableValue = $actual . ' (' . bin2hex($actual) . ')';
            $readableExpectedValue = $expectation->value . ' (' . bin2hex($expectation->value) . ')';

            if ($expectation->address !== $address) {
                throw new ExpectationException("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress", 1);
            }
            
            if ($actual !== $expectation->value) {
                throw new ExpectationException("Unexpected char* write value $readableValue to $readableAddress, expecting $readableExpectedValue", 1);
            }

            $this->fulfilled("Wrote string $readableValue to $readableAddress");
        }
        // Hanlde int writes
        else {
            if (!($expectation instanceof WriteExpectation)) {
                throw new ExpectationException("Unexpected int write of $readableValue to $readableAddress, expecting char* write of $readableExpectedAddress", 1);
            }

            if ($value::BIT_COUNT !== $expectation->size) {
                throw new ExpectationException("Unexpected " . $value::BIT_COUNT . " bit write of $readableValue to $readableAddress, expecting $expectation->size bit write", 1);
            }

            $readableExpectedValue = $expectation->value . '(0x' . dechex($expectation->value) . ')';
            if ($expectation->address !== $address) {
                throw new ExpectationException("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress", 1);
            }

            if ($value->lessThan(0)) {
                throw new ExpectationException("Unexpected negative write value $readableValue to $readableAddress", 1);
            }

            if (!$value->equals($expectation->value)) {
                throw new ExpectationException("Unexpected write value $readableValue to $readableAddress, expecting value $readableExpectedValue", 1);
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
