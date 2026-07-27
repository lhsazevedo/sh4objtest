<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\CallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\BranchOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\ReadOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\WriteOperation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallCommand;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReturnExpectation;

class Run
{
    private ?string $disasm = null;

    /** @var string[] */
    private array $registerLog = [];

    /** @var string[] */
    private array $messages = [];

    private CoverageTracker $coverage;

    private ExpectationMatcher $matcher;

    private ?BranchOperation $delayedBranch = null;

    private bool $running = true;

    public function __construct(
        private EventListener $events,
        private TestCaseDTO $testCase,
        private bool $shouldOutputDisasm,
        private ArgumentVerifier $argumentVerifier = new ArgumentVerifier(),
        private ReturnValueVerifier $returnValueVerifier = new ReturnValueVerifier(),
    )
    {
        $this->coverage = new CoverageTracker();
    }

    public function run(): RunResult
    {
        $memory = new BinaryMemory(
            1024 * 1024 * 16,
            randomize: $this->testCase->shouldRandomizeMemory,
        );

        $program = $this->testCase->linkedProgram;
        $memory->writeBytes(0, $program->image);

        $this->matcher = new ExpectationMatcher(
            $this->testCase->expectations,
            $program->symbols,
            $this->testCase->testRelocations,
            $this->testCase->defaultCallbacks,
            $this->testCase->defaultConventions,
            $program->unresolvedRelocations,
            $this->argumentVerifier,
            $this->fulfilled(...),
            $this->logInfo(...),
        );

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

        $simulator = new Simulator($memory);

        $simulator->onDisasm($this->disasm(...));
        $simulator->onAddLog($this->addLog(...));

        $command = $this->matcher->peek();
        if (!$command instanceof CallCommand) {
            throw new \Exception("First step must be a call command", 1);
        }
        $entrySymbolName = $command->symbol;

        $entryAddress = $program->resolveEntryAddress($entrySymbolName);
        if ($entryAddress === null) throw new \Exception("Entry symbol {$entrySymbolName} not found.", 1);
        $simulator->setPc($entryAddress);

        $convention = new DefaultCallingConvention();
        $stackPointer = U32::of(1024 * 1024 * 16 - 4);
        $simulator->setRegister(15, $stackPointer);

        do {
            $this->running = true;

            $command = $this->matcher->peek();
            if (!($command instanceof CallCommand)) {
                throw new \Exception(
                    "First or after return must be a call command", 1
                );
            }
            $this->setupArguments($simulator, $convention, $command->arguments);
            $this->matcher->shift();

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
                    $this->coverage->logWrite($instruction->target->value, (int) $instruction->value::BIT_COUNT / 8);
                    $this->matcher->matchWrite($simulator, $instruction);
                } else if ($instruction instanceof ReadOperation) {
                    $this->coverage->logRead($instruction->source->value, $instruction->value::BIT_COUNT / 8);
                    $this->matcher->matchRead($simulator, $instruction);
                }

                $this->outputMessages();

                // Stop on RTS
                if ($instruction->opcode === 0x000B) {
                    $this->logInfo("Program returned");
                    $this->stop();
                    // TODO: Add return expectation check here,
                    // but we'll need to wait for the delayed return.
                }

                if ($delayedBranch) {
                    // Call matchBranch only if the delayed branch is not an RTS
                    if ($delayedBranch->opcode !== 0x000B) {
                        if ($this->matcher->matchBranch($simulator, $delayedBranch)) {
                            $this->stop();
                        }
                    }
                    $this->delayedBranch = null;
                }

                $this->outputMessages();

                if ($this->testCase->shouldStopWhenFulfilled && $this->matcher->isEmpty()) {
                    break;
                }
            }

            $returnExpectation = $this->matcher->peek();
            if ($returnExpectation && ($returnExpectation instanceof ReturnExpectation)) {
                $this->matcher->shift();

                $message = $this->returnValueVerifier->verify($simulator, $returnExpectation->value);
                $this->fulfilled($message);
            }
        } while ($this->matcher->peek() instanceof CallCommand);

        if (!$this->matcher->isEmpty()) {
            $names = array_map(fn ($e) => $e::class, $this->matcher->remaining());
            throw new \Exception("Pending expectations: " . implode(', ', $names), 1);
        }

        $this->outputMessages();

        $count = count($this->testCase->expectations);

        $name = self::humanizeName($this->testCase->name);

        $expectationsMessage = match (true) {
            $count === 0 => "<fg=yellow>no expectations</>",
            $count === 1 => "1 expectation",
            default => "$count expectations",
        };

        $this->events->onTestPassed($name, $expectationsMessage);

        return new RunResult(
            name: $name,
            message: $expectationsMessage,
            coverage: $this->coverage,
        );
    }

    public static function humanizeName(string $name): string
    {
        $name = preg_replace('/^test_?/', '', $name, 1);
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        return ucfirst(strtolower($name));
    }

    /**
     * @param array<int|float> $arguments
     */
    private function setupArguments(
        Simulator $simulator,
        CallingConvention $convention,
        array $arguments,
    ): void
    {
        $stackPointer = $simulator->getRegister(15);
        foreach ($arguments as $argument) {
            $storage = $convention->takeArgumentStorageForValue($argument);

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
            $simulator->getMemory()
                ->writeUInt32($stackPointer->value, U32::of($argument));
        }
        $simulator->setRegister(15, $stackPointer);
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
            $this->events->onDisasm($disasm);
        }

        foreach ($this->messages as $message) {
            $this->events->onMessage($message);
        }

        $this->disasm = null;
        $this->messages = [];
        $this->registerLog = [];
    }

    private function fulfilled(string $message): void {
        $this->handleMessage("<fg=green>✔ Fulfilled: $message</>");
    }

    private function logInfo(string $str): void {
        $this->handleMessage("<fg=blue>$str</>");
    }

    private function addLog(Simulator $simulator, string $str): void {
        $this->registerLog[] = $str;
    }

    /**
     * Either output message or store it for later when in disasm mode
     */
    private function handleMessage(string $message): void
    {
        if (!$this->shouldOutputDisasm) {
            return;
        }

        $this->messages[] = $message;
    }

    private function stop(): void
    {
        $this->running = false;
    }
}
