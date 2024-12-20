<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\ObjectParser;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\Symbol;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\TestCase;
use ReflectionClass;

class Runner
{
    private ?string $disasm = null;

    private ?string $delaySlotDisasm = null;

    private bool $shouldDisasm = false;

    /** @var string[] */
    private array $registerLog = [];

    /** @var string[] */
    private array $delaySlotRegisterLog = [];

    /** @var string[] */
    private array $messages = [];

    /** @var string[] */
    private array $delaySlotMessages = [];


    public function __construct(
        private InputInterface $input,
        private OutputInterface $output,
        private bool $shouldOutputDisasm = false,
    )
    {
    }

    public function runFile($testFile, $objectFile)
    {
        $testCase = require $testFile;
        $reflectedBaseTestCase = new ReflectionClass(TestCase::class);
        $reflectedTestCase = new ReflectionClass($testCase);

        // TODO: Check if it is really necessary to
        // pass the parsed object to the test case.
        $parsedObject = ObjectParser::parse($objectFile);
        $linkedCode = $this->linkObject($parsedObject);

        try {
            foreach ($reflectedTestCase->getMethods() as $reflectionMethod) {
                if (!$reflectionMethod->isPublic()) {
                    continue;
                }

                if (!str_starts_with($reflectionMethod->name, 'test')) {
                    continue;
                }

                $this->output->writeln("{$reflectionMethod->name}...");
                /** @var TestCase */
                $currentTestCase = require $testFile;
                $currentTestCase->setParsedObject($parsedObject);
                $currentTestCase->setObjectFile($objectFile);
                call_user_func([$currentTestCase, $reflectionMethod->name]);

                $testCaseDto = new TestCaseDTO(
                    name: $reflectionMethod->name,
                    objectFile: $objectFile,
                    parsedObject: $parsedObject,
                    initializations: $reflectedBaseTestCase->getProperty('initializations')->getValue($currentTestCase),
                    testRelocations: $reflectedBaseTestCase->getProperty('testRelocations')->getValue($currentTestCase),
                    expectations: $reflectedBaseTestCase->getProperty('expectations')->getValue($currentTestCase),
                    entry: $reflectedBaseTestCase->getProperty('entry')->getValue($currentTestCase),
                    linkedCode: $linkedCode,
                    shouldRandomizeMemory: $reflectedBaseTestCase->getProperty('randomizeMemory')->getValue($currentTestCase),
                    shouldStopWhenFulfilled: $reflectedBaseTestCase->getProperty('forceStop')->getValue($currentTestCase),
                );

                $this->run($testCaseDto);
            }
        } catch (ExpectationException $e) {
            $this->output->writeln("\n<bg=red> FAILED EXPECTATION </> <fg=red>{$e->getMessage()}</>\n");
            return false;
        }

        return true;
    }

    private function run(TestCaseDTO $testCaseDto): void
    {
        $memory = new BinaryMemory(
            1024 * 1024 * 16,
            randomize: $testCaseDto->shouldRandomizeMemory,
        );

        $parsedObject = $testCaseDto->parsedObject;
        $linkedCode = $testCaseDto->linkedCode;

        // TODO: Linking should be done here
        $memory->writeBytes(0, $linkedCode);

        $symbols = new SymbolTable();

        // Initializations (FIXME: bad name)
        foreach ($testCaseDto->initializations as $initialization) {
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

        $unresolvedRelocations = [];
        foreach ($parsedObject->unit->sections as $section) {
            foreach ($section->relocations as $relocation) {
                $found = false;

                // FIXME: This is confusing:
                // - Object relocation address is the address of the literal pool data item
                // - Test relocation address is the value of the literal pool item
                foreach ($testCaseDto->testRelocations as $userResolution) {
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
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $unresolvedRelocations[] = $relocation;
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

        $simulator = new Simulator(
            input: $this->input,
            output: $this->output,
            symbols: $symbols,
            expectations: $testCaseDto->expectations,
            entry: $testCaseDto->entry,
            forceStop: $testCaseDto->shouldStopWhenFulfilled,
            testRelocations: $testCaseDto->testRelocations,
            memory: $memory,
        );

        // TODO: We shouldn't be checking for relocations on each read...
        // There should be a more performant way of handling this.
        $simulator->onReadUInt(
            function (
                Simulator $sim, int $address, int $offset, int $size
            ) use ($unresolvedRelocations) {
                foreach ($unresolvedRelocations as $relocation) {
                    if ($relocation->linkedAddress !== $address + $offset) {
                        continue;
                    }

                    throw new \Exception(
                        "Trying to read from unresolved relocation $relocation->name",
                        1
                    );
                }
            }
        );

        $simulator->onDisasm($this->disasm(...));
        $simulator->onInstruction(fn () => $this->outputMessages());
        $simulator->onPhpException(fn () => $this->outputMessages());
        $simulator->onFullfilled($this->fulfilled(...));
        $simulator->onAddLog($this->addLog(...));
        $simulator->onInfo($this->logInfo(...));

        $entrySymbol = $parsedObject->unit->findExportedSymbol($testCaseDto->entry->symbol);
        if (!$entrySymbol) throw new \Exception("Entry symbol {$testCaseDto->entry->symbol} not found.", 1);
        $simulator->setPc($entrySymbol->offset);

        $convention = new DefaultCallingConvention();
        $stackPointer = U32::of(1024 * 1024 * 16 - 4);
        // TODO: Rename to arguments
        foreach ($testCaseDto->entry->parameters as $argument) {
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

        if ($this->shouldOutputDisasm) {
            $this->enableDisasm();
        }

        $simulator->run();
    }

    protected function linkObject(ParsedObject $object): string {
        $linkedCode = '';
        // TODO: Handle multiple units?
        foreach ($object->unit->sections as $section) {
            // Align
            $remainder = strlen($linkedCode) % $section->alignment;
            if ($remainder) {
                $linkedCode .= str_repeat("\0", $section->alignment - $remainder);
            }

            $section->rellocate(strlen($linkedCode));

            $linkedCode .= $section->assembleObjectData();
        }

        return $linkedCode;
    }

        /**
     * @param string $instruction
     * @param string[] $opcode
     */
    private function disasm($simulator, string $instruction, array $operands = []): void
    {
        // if (!$this->shouldDisasm) {
        //     return;
        // }

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
        $line .= $simulator->getInDelaySlot() ? '_' : ' ';

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

        if ($simulator->getInDelaySlot()) {
            $this->delaySlotDisasm = $line;
        } else {
            $this->disasm = $line;
        }
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

    private function fulfilled(Simulator $simulator, string $message): void {
        $this->handleMessage($simulator, "<fg=green>✔ Fulfilled: $message</>");
    }

    private function logInfo(Simulator $simulator, string $str): void {
        $this->handleMessage($simulator, "<fg=blue>$str</>");
    }

    private function addLog(Simulator $simulator, string $str): void {
        if ($simulator->getInDelaySlot()) {
            $this->delaySlotRegisterLog[] = $str;
            return;
        }

        $this->registerLog[] = $str;
    }

    /**
     * Either output message or store it for later when in disasm mode
     */
    private function handleMessage(Simulator $simulator, string $message): void
    {
        if ($simulator->getInDelaySlot()) {
            $this->delaySlotMessages[] = $message;
            return;
        }

        $this->messages[] = $message;
    }
}
