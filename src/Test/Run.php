<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\ArgumentType;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\StackOffset;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\Symbol;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\BranchOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\ReadOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\WriteOperation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReadExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\StringWriteExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\WriteExpectation;

class Run
{
    private ?string $disasm = null;

    /** @var \Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation[] */
    private array $expectations;

    /** @var \Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation[] */
    private array $pendingExpectations;

    /** @var string[] */
    private array $registerLog = [];

    /** @var string[] */
    private array $messages = [];

    private SymbolTable $symbols;

    private CoverageTracker $coverage;

    /** @var \Lhsazevedo\Sh4ObjTest\Parser\Chunks\Relocation[] */
    private array $unresolvedRelocations = [];

    private ?BranchOperation $delayedBranch = null;

    private bool $running = true;

    public function __construct(
        private OutputInterface $output,
        private TestCaseDTO $testCase,
        private bool $shouldOutputDisasm,
    )
    {
        $this->expectations = $testCase->expectations;
        $this->pendingExpectations = $testCase->expectations;
        $this->coverage = new CoverageTracker();
    }

    public function run(): RunResult
    {
        $memory = new BinaryMemory(
            1024 * 1024 * 16,
            randomize: $this->testCase->shouldRandomizeMemory,
        );

        $parsedObject = $this->testCase->parsedObject;
        $linkedCode = $this->testCase->linkedCode;

        // TODO: Linking should be done here
        $memory->writeBytes(0, $linkedCode);

        $symbols = new SymbolTable();
        $this->symbols = $symbols;

        // Initializations (FIXME: bad name)
        foreach ($this->testCase->initializations as $initialization) {
            switch ($initialization->size) {
                case U8::BIT_COUNT:
                    // TODO: Use SInt value object
                    $memory->writeUInt8($initialization->address, U8::of($initialization->value & U8::MAX_VALUE));
                    break;

                case U16::BIT_COUNT:
                    // TODO: Use SInt value object
                    $memory->writeUInt16($initialization->address, U16::of($initialization->value & U16::MAX_VALUE));
                    break;

                case U32::BIT_COUNT:
                    // TODO: Use SInt value object
                    $memory->writeUInt32($initialization->address, U32::of($initialization->value & U32::MAX_VALUE));
                    break;

                default:
                    throw new \Exception("Unsupported initialization size $initialization->size", 1);
            }
        }

        // TODO: Does not need to happen every run.
        // TODO: TestCase shouldn't have access to the parsed object
        foreach ($parsedObject->unit->sections as $section) {
            foreach ($section->localRelocationsLong as $lr) {
                $targetSection = $parsedObject->unit->sections[$lr->sectionIndex];

                $memory->writeUInt32(
                    $section->linkedAddress + $lr->address,
                    U32::of($targetSection->linkedAddress + $lr->target),
                );
            }
        }

        // TODO: Does not need to happen every run.
        // TODO: Consolidate section loop above?
        foreach ($parsedObject->unit->sections as $section) {
            foreach ($section->localRelocationsShort as $lr) {
                $offset = $memory->readUInt32($section->linkedAddress + $lr->address);
                $targetSection = $parsedObject->unit->sections[$lr->sectionIndex];

                $memory->writeUInt32(
                    $section->linkedAddress + $lr->address,
                    U32::of($targetSection->linkedAddress)->add($offset),
                );
            }
        }

        foreach ($parsedObject->unit->sections as $section) {
            foreach ($section->relocations as $relocation) {
                $found = false;

                // FIXME: This is confusing:
                // - Object relocation address is the address of the literal pool data item
                // - Test relocation address is the value of the literal pool item
                foreach ($this->testCase->testRelocations as $userResolution) {
                    if ($relocation->name === $userResolution->name) {
                        $offset = $memory->readUInt32($relocation->linkedAddress)->value;

                        if ($relocation->offset && $offset) {
                            throw new \Exception("Relocation $relocation->name has both built-in and code offset", 1);
                            // $this->output->writeln("WARN: Relocation $relocation->name has both built-in and code offset");
                            // $this->output->writeln("Built-in offset: $offset");
                            // $this->output->writeln("Code offset: $relocation->offset");
                        }

                        $memory->writeUInt32(
                            $relocation->linkedAddress,
                            U32::of($userResolution->address + $relocation->offset + $offset),
                        );
                        $symbols->addSymbol(new Symbol(
                            $relocation->name,
                            U32::of($userResolution->address + $relocation->offset + $offset),
                        ));
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $this->unresolvedRelocations[] = $relocation;
                }
            }
        }

        foreach ($parsedObject->unit->sections as $section) {
            foreach ($section->exports as $export) {
                $symbols->addSymbol(new Symbol(
                    $export->name,
                    U32::of($export->linkedAddress),
                ));
            }
        }

        $simulator = new Simulator($memory);

        $simulator->onDisasm($this->disasm(...));
        $simulator->onAddLog($this->addLog(...));

        $entrySymbol = $parsedObject->unit->findExportedSymbol($this->testCase->entry->symbol);
        if (!$entrySymbol) throw new \Exception("Entry symbol {$this->testCase->entry->symbol} not found.", 1);
        $simulator->setPc($entrySymbol->offset);

        $convention = new DefaultCallingConvention();
        $stackPointer = U32::of(1024 * 1024 * 16 - 4);
        // TODO: Rename to arguments
        foreach ($this->testCase->entry->parameters as $argument) {
            /** @var int|float $argument */

            $storage = $convention->getNextArgumentStorageForValue($argument);

            if ($storage instanceof GeneralRegister) {
                $simulator->setRegister($storage->index(), U32::of($argument));
                continue;
            }

            if ($storage instanceof FloatingPointRegister) {
                $simulator->setFloatRegister($storage->index(), $argument);
                continue;
            }

            // FIXME: Stack offset must be controlled by the calling convention.
            $stackPointer = $stackPointer->sub(4);
            $memory->writeUInt32($stackPointer->value, U32::of($argument));
        }
        $simulator->setRegister(15, $stackPointer);

        while ($this->running || $simulator->nextIsDelaySlot()) {
            // By hadling the returned instruction instead of the actual
            // operation that was done, this code is doomed to be messy.
            // We should have a intermediary class, or even make the simulator
            // emit an event when the actual operation is done.

            $delayedBranch = $this->delayedBranch;

            try {
                $this->coverage->logExecute($simulator->getPc(), 2);
                $instruction = $simulator->step();
            } catch (\Exception $e) {
                throw $e;
            } finally {
                // TODO: Refator duplicated calls to outputMessages
                $this->outputMessages();
            }

            // TODO: Refactor to match expression
            if ($instruction instanceof BranchOperation) {
                $this->delayedBranch = $instruction;
            } else if ($instruction instanceof WriteOperation) {
                $this->onWrite($simulator, $instruction);
            } else if ($instruction instanceof ReadOperation) {
                $this->onRead($simulator, $instruction);
            }

            $this->outputMessages();

            // Stop on RTS
            if ($instruction->opcode === 0x000B) {
                $this->logInfo($simulator, "Program returned");
                $this->stop();
                // TODO: Add return expectation check here,
                // but we'll need to wait for the delayed return.
            }

            if ($delayedBranch) {
                // Call onBranch only if the delayed branch is not an RTS
                if ($delayedBranch->opcode !== 0x000B) {
                    $this->onBranch($simulator, $delayedBranch);
                }
                $this->delayedBranch = null;
            }

            $this->outputMessages();

            if ($this->testCase->shouldStopWhenFulfilled && !$this->pendingExpectations) {
                break;
            }
        }

        if ($this->pendingExpectations) {
            var_dump($this->pendingExpectations);
            throw new \Exception("Pending expectations", 1);
        }

        $expectedReturn = $this->testCase->entry->return;
        $actualReturn = $simulator->getRegister(0);
        if ($expectedReturn !== null) {
            if (!$actualReturn->equals($expectedReturn)) {
                throw new ExpectationException("Unexpected return value $actualReturn, expecting $expectedReturn");
            }

            $this->fulfilled($simulator, "Returned $expectedReturn");
        }

        // TODO: returns and float returns are mutually exclusive
        if ($this->testCase->entry->floatReturn !== null) {
            $expectedFloatReturn = $this->testCase->entry->floatReturn;
            $actualFloatReturn = $simulator->getFloatRegister(0);
            $expectedDecRepresentation = unpack('L', pack('f', $expectedFloatReturn))[1];
            $actualDecRepresentation = unpack('L', pack('f', $actualFloatReturn))[1];

            if ($actualDecRepresentation !== $expectedDecRepresentation) {
                throw new ExpectationException("Unexpected return value $actualFloatReturn, expecting $expectedFloatReturn");
            }

            $this->fulfilled($simulator, "Returned float $expectedFloatReturn");
        }

        $this->outputMessages();

        $count = count($this->expectations);
        if ($expectedReturn || $this->testCase->entry->floatReturn !== null) {
            $count++;
        }

        $name = $this->testCase->name;
        $name = preg_replace('/^test_?/', '', $name, 1);
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        $name = ucfirst(strtolower($name));

        $expectationsMessage = match (true) {
            $count === 0 => "<fg=yellow>no expectations</>",
            $count === 1 => "1 expectation",
            default => "$count expectations",
        };

        $this->output->writeln("    <fg=bright-green;options=bold>✔</> $name ($expectationsMessage)");

        return new RunResult(
            success: true,
            coverage: $this->coverage,
        );
    }

    /**
     * @param string[] $operands
     */
    private function disasm(Simulator $simulator, string $instruction, array $operands = []): void
    {
        if (!$this->shouldOutputDisasm) {
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

        $addr = str_pad(dechex($simulator->getDisasmPc()), 6, '0', STR_PAD_LEFT);

        $line = "<fg=gray>0x$addr " . $simulator->getMemory()->readUInt16($simulator->getDisasmPc())->hex() . "</> ";
        $line .= $simulator->inDelaySlot() ? '_' : ' ';

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

        $this->disasm = $line;
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

        foreach ($this->messages as $message) {
            $this->output->writeln($message);
        }

        $this->disasm = null;
        $this->messages = [];
        $this->registerLog = [];
    }

    private function fulfilled(Simulator $simulator, string $message): void {
        $this->handleMessage($simulator, "<fg=green>✔ Fulfilled: $message</>");
    }

    private function logInfo(Simulator $simulator, string $str): void {
        $this->handleMessage($simulator, "<fg=blue>$str</>");
    }

    private function addLog(Simulator $simulator, string $str): void {
        $this->registerLog[] = $str;
    }

    /**
     * Either output message or store it for later when in disasm mode
     */
    private function handleMessage(Simulator $simulator, string $message): void
    {
        if (!$this->shouldOutputDisasm) {
            return;
        }

        $this->messages[] = $message;
    }

    private function onBranch(Simulator $simulator, BranchOperation $instruction): void
    {
        // Branch to symbols are calls and must be expected
        if ($this->symbols->getSymbolAtAddress($instruction->target)) {
            $this->assertCall($simulator, $instruction->target->value);

            if ($instruction->isCall()) {
                $simulator->setPc($simulator->getPr());
                $simulator->cancelDelayedBranch();
            } else {
                // Program jumped to another symbol.
                $this->logInfo($simulator, "Program jumped to symbol at " . $instruction->target->hex());
                $this->stop();
            }
            return;
        }

        // Branch to non-symbol are checked only
        // if the address matches the expectation
        $expectation = reset($this->pendingExpectations);
        if ($expectation instanceof CallExpectation && $instruction->target->equals($expectation->address)) {
            $this->assertCall($simulator, $instruction->target->value);

            if ($instruction->isCall()) {
                $simulator->setPc($simulator->getPr());
                $simulator->cancelDelayedBranch();
            }
            // Stop execution on dynamic tail calls
            else {
                $this->logInfo($simulator, "Program jumped to address " . $instruction->target->hex());
                $this->stop();
            }
        }
    }

    private function onWrite(Simulator $simulator, WriteOperation $instruction): void
    {
        $address = $instruction->target->value;
        $value = $instruction->value;
        $this->coverage->logWrite(
            $address, (int) $instruction->value::BIT_COUNT / 8
        );

        $expectation = reset($this->pendingExpectations);
        $readableAddress = '0x' . dechex($address);
        $readableValue = $value->readable();

        // TODO: I really don't like how we need to keep checking for the expectation type here.

        // Stack write
        if ($address >= $simulator->getRegister(15)->value) {
            // Unexpected stack writes are allowed
            if (!($expectation instanceof WriteExpectation
                    || $expectation instanceof StringWriteExpectation)
                || $expectation->address !== $address
            ) {
                $this->logInfo($simulator, "Allowed stack write of $readableValue to $readableAddress");
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

            $actual = $simulator->getMemory()->readString($value->value);
            $readableValue = $actual . ' (' . bin2hex($actual) . ')';
            $readableExpectedValue = $expectation->value . ' (' . bin2hex($expectation->value) . ')';

            if ($expectation->address !== $address) {
                throw new ExpectationException("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress");
            }

            if ($actual !== $expectation->value) {
                throw new ExpectationException("Unexpected char* write value $readableValue to $readableAddress, expecting $readableExpectedValue");
            }

            $this->fulfilled($simulator, "Wrote string $readableValue to $readableAddress");
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

            $this->fulfilled($simulator, "Wrote $readableValue to $readableAddress");
        }

        array_shift($this->pendingExpectations);
    }

    private function onRead(Simulator $simulator, ReadOperation $instruction): void
    {
        $this->coverage->logRead(
            $instruction->source->value, $instruction->value::BIT_COUNT / 8
        );

        foreach ($this->unresolvedRelocations as $relocation) {
            if ($relocation->linkedAddress !== $instruction->source->value) {
                continue;
            }

            throw new \Exception(
                "Trying to read from unresolved relocation $relocation->name",
                1
            );
        }

        $displacedAddr = $instruction->source->value;

        $readableAddress = '0x' . dechex($displacedAddr);
        if ($symbol = $this->getSymbolNameAt($displacedAddr)) {
            $readableAddress = "$symbol($readableAddress)";
        }

        $expectation = reset($this->pendingExpectations);

        // if ($value instanceof Relocation) {
        //     throw new \Exception("Trying to read relocation $value->name in $readableAddress");
        // }

        $value = $instruction->value;
        $readableValue = $value . ' (0x' . dechex($value->value) . ')';

        $size = $instruction->value::BIT_COUNT;

        // Handle read expectations
        if ($expectation instanceof ReadExpectation && $expectation->address === $displacedAddr) {
            $readableExpected = $expectation->value . ' (0x' . dechex($expectation->value) . ')';

            if ($size !== $expectation->size) {
                throw new ExpectationException("Unexpected read size $size from $readableAddress. Expecting size $expectation->size");
            }

            if (!$value->equals($expectation->value)) {
                throw new ExpectationException("Unexpected read of $readableValue from $readableAddress. Expecting value $readableExpected");
            }

            $this->fulfilled($simulator, "Read $readableExpected from $readableAddress");
            array_shift($this->pendingExpectations);
        }
    }

    private function assertCall(Simulator $simulator, int $target): void
    {
        $name = null;
        $readableName = "<NO_SYMBOL>";

        if ($export = $this->symbols->getSymbolAtAddress(U32::of($target))) {
            $name = $export->name;
            $readableName = "$name (" . U32::of($target)->hex() . ")";
        } elseif ($resolution = $this->getResolutionAt($target)) {
            $name = $resolution->name;
            $readableName = "$name (" . U32::of($target)->hex() . ")";
        }

        // FIXME: modls and modlu probrably behave differently
        if ($name === '__modls' || $name === '__modlu') {
            $simulator->setRegister(0, $simulator->getRegister(1)->mod($simulator->getRegister(0)));
            return;
        }

        if ($name === '__divls') {
            $simulator->setRegister(0, $simulator->getRegister(1)->div($simulator->getRegister(0)));
            return;
        }

        /** @var Expectations\AbstractExpectation */
        $expectation = array_shift($this->pendingExpectations);

        if (!($expectation instanceof CallExpectation)) {
            throw new ExpectationException("Unexpected function call to $readableName at " . dechex($simulator->getPc()));
        }

        if ($name !== $expectation->name) {
            throw new ExpectationException("Unexpected call to $readableName at " . dechex($simulator->getPc()) . ", expecting $expectation->name");
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
                        $actual = $simulator->getRegister($register);
                        $actualHex = dechex($actual->value);
                        $expectedHex = dechex($expected);
                        if (!$actual->equals($expected)) {
                            throw new ExpectationException("Unexpected argument for $readableName in r$register. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)");
                        }

                        continue;
                    }

                    if ($storage instanceof StackOffset) { 
                        $offset = $storage->offset;

                        $address = $simulator->getRegister(15)->value + $offset;
                        $actual = $simulator->getMemory()->readUInt32($address);

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
                        $actual = $simulator->getFloatRegister($register);
                        $actualDecRepresentation = unpack('L', pack('f', $actual))[1];
                        $expectedDecRepresentation = unpack('L', pack('f', $expected))[1];
                        if ($actualDecRepresentation !== $expectedDecRepresentation) {
                            throw new ExpectationException("Unexpected float argument for $readableName in fr$register. Expected $expected, got $actual");
                        }
    
                        continue;
                    }

                    if ($storage instanceof StackOffset) {
                        $offset = $storage->offset;

                        $address = $simulator->getRegister(15)->value + $offset;
                        $actualDecRepresentation = $simulator->getMemory()->readUInt32($address);
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
                        $address = $simulator->getRegister($register);

                        $actual = $simulator->getMemory()->readString($address->value);
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
            $callback = \Closure::bind($expectation->callback, $simulator, $simulator);
            $callback($expectation->parameters);
        }

        if ($expectation->return !== null) {
            $simulator->setRegister(0, U32::of($expectation->return & 0xffffffff));
        }

        $this->fulfilled($simulator, "Called " . $readableName . '(0x'. dechex($target) . ")");
    }

    private function getResolutionAt(int $address): ?TestRelocation
    {
        foreach ($this->testCase->testRelocations as $relocation) {
            if ($relocation->address === $address) {
                return $relocation;
            }
        }

        return null;
    }

    private function getSymbolNameAt(int $address): ?string
    {
        if ($relocation = $this->getResolutionAt($address)) {
            return $relocation->name;
        }

        if ($export = $this->symbols->getSymbolAtAddress(U32::of($address))) {
            return $export->name;
        }

        return null;
    }

    private function stop(): void
    {
        $this->running = false;
    }
}
