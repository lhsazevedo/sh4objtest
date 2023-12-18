<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim;

use Lhsazevedo\Objsim\Simulator\BinaryMemory;

// TODO: Remove from global
function getN(int $op): int
{
	return ($op >> 8) & 0xf;
}
function getM(int $op): int
{
	return ($op >> 4) & 0xf;
}
function getImm4($instruction) { return $instruction & 0xf; }
function getImm8($instruction) { return $instruction & 0xff; }

class Simulator
{
    private int $entryAddress;

    private bool $running = false;

    private int $pc;

    // TODO: Handle float and system registers
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

    private int $pr = 0;

    private BinaryMemory $memory;

    /** @var AbstractExpectation[] */
    private array $pendingExpectations;

    public function __construct(
        private ParsedObject $object,

        /** @var AbscractExpectation[] */
        private array $expectations,

        private string $entry,
    )
    {
        $this->pendingExpectations = $expectations;

        // Search entry in exports
        /** @var ExportSymbol */
        $entrySymbol = map($object->exports)->find(fn (ExportSymbol $e) => $e->name === $entry);

        $this->entryAddress = $entrySymbol->offset;
    }

    public function run()
    {
        $this->running = true;
        $this->pc = $this->entryAddress;
        $this->memory = new BinaryMemory(1024);
        $this->registers[15] = 1024;

        $this->memory->writeBytes(0, $this->object->code);

        while ($this->running) {
            $instruction = $this->readInstruction($this->pc);
            $this->pc += 2;
            $this->executeInstruction($instruction);
        }

        if ($this->pendingExpectations) {
            var_dump($this->pendingExpectations);
            throw new \Exception("Pending expectations", 1);
        }

        echo "Passed\n";
    }

    public function readInstruction(int $address)
    {
        return $this->memory->readUInt16($address);
    }

    public function executeDelaySlot()
    {
        // TODO: refactor duplicated code
        $instruction = $this->readInstruction($this->pc);
        $this->pc += 2;
        $this->executeInstruction($instruction);
    }

    public function executeInstruction(int $instruction)
    {
        switch ($instruction) {
            // NOP
            case 0x0009:
                // Do nothing
                return;

            // RTS
            case 0x000b:
                $this->executeDelaySlot();
                // TODO: Handle simulated calls
                $this->running = false;
                return;
        }

        // f0ff
        switch ($instruction & 0xf0ff) {
            // JSR
            case 0x400b:
                $n = getN($instruction);

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

            // STS.L PR,@-<REG_N>
            case 0x4022:
                $n = getN($instruction);
                $address = $this->registers[$n] - 4;
                $this->memory->writeUint32($address, $this->pr);
                $this->registers[$n] = $address;
                return;

            case 0x4026:
                $n = getN($instruction);
                $this->pr = $this->memory->readUInt32($this->registers[$n]);

                $this->registers[$n] += 4;
                return;

            // JMP
            case 0x402b:
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
        }

        // f000
        switch ($instruction & 0xf000) {
            // mov.l @(<disp>,PC),<REG_N>
            case 0xd000:
                $n = getN($instruction);
                $disp = getImm8($instruction);

                // TODO: Handle rellocation and expectations
                $addr = $disp * 4 + (($this->pc + 2) & 0xFFFFFFFC);

                $data = $this->memory->readUInt32($addr);

                if ($relocation = $this->object->getRelocationAt($addr)) {
                    $data = $relocation;
                }

                $this->registers[$n] = $data;
                return;
        }

        throw new \Exception("Unknown instruction " . dechex($instruction), 1);
    }

    private function assertCall(string $name): void
    {
        /** @var AbstractExpectation */
        $expectation = array_shift($this->expectations);

        if (!($expectation && $expectation instanceof CallExpectation)) {
            throw new \Exception("Unexpected call to $name at " . dechex($this->pc), 1);
        }
    }
}
