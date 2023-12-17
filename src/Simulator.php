<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim;

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

    // TODO: Better registers
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

        while ($this->running) {
            $instruction = $this->readInstruction($this->pc);
            $this->executeInstruction($instruction);

            // TODO: Fails on branches
            $this->pc += 2;
        }

        if (!map($this->pendingExpectations)->empty()) {
            var_dump($this->pendingExpectations);
            throw new \Exception("Pending expectations", 1);
        }
    }

    public function readInstruction(int $address)
    {
        $data = substr($this->object->code, $address, 2);
        $unpacked = unpack("v", $data);
        return $unpacked[1];
    }

    public function executeDelaySlot()
    {
        $instruction = $this->readInstruction($this->pc + 2);
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
            // JMP
            case 0x402b:
                $n = getN($instruction);
                $newPc = $this->registers[$n];
                $this->executeDelaySlot();
                $this->pc = $newPc;
                // TODO: Handle expectation;
                return;
        }

        // f000
        switch ($instruction & 0xf000) {
            // mov.l @(<disp>,PC),<REG_N>
            case 0xd000:
                $n = getN($instruction);
                $disp = getImm8($instruction);

                // TODO: Handle rellocation and expectations
                $this->registers[$n] = $this->readU32($disp * 4 + (($this->pc + 2) & 0xFFFFFFFC));
                return;
        }


        throw new \Exception("Unknown instruction " . dechex($instruction), 1);
    }

    private function readU32($address) {
        $data = substr($this->object->code, $address, 4);
        $unpacked = unpack("V", $data);
        return $unpacked[1];
    }
}
