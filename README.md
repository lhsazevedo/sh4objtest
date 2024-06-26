# Hitachi SH4 Object Tester

Hitachi SH4 object tester is a simulator and testing framework designed to aid in the Tokyo Bus
Guide [decompilation project](https://github.com/lhsazevedo/tokyo-bus-guide-decomp). This tool
allows you to run and test SH4 object files using a set of predefined expectations and test cases.

⚠️ This project is in the experimental stage.

## Usage

To use the Hitachi SH4 object tester, run the following command:

```
sh4objtest "path/to/test.php" "path/to/objectfile.obj"
```

For example:
```
sh4objtest "tests/0100bc_sound/0100bc_initUknVol.php" "build/output/0100bc_sound_src.obj"
```

## Writing Test Cases

Test cases are written by extending the `TestCase` class. Here is an example of how to create a test case:

```php
<?php

return new class extends TestCase {
    public function test_drawFirstOption()
    {
        $task = 0xbebacafe;

        $this->shouldCall('_setTaskAction')->with($task, $this->addressOf('_VmMenuTask'));
        $this->shouldWriteLong($this->addressOf('_menuState') + 0x18, 0);
        $this->shouldWriteLong($this->addressOf('_menuState') + 0x38, 0);
        $this->shouldWriteLong($this->addressOf('_menuState') + 0x68, 0);

        $this->call('_VmMenuSwitchFromTask')->with($task)->run();
    }
};
```

## TestCase Interface

The `TestCase` class provides a set of methods to define expectations and interactions with the SH4 object file. Here are some of the key methods available:

- `shouldCall(string|int $target): CallExpectation`
- `shouldRead(int $address, int $value): ReadExpectation`
- `shouldReadLong(int $address, int $value): ReadExpectation`
- `shouldReadWord(int $address, int $value): ReadExpectation`
- `shouldReadByte(int $address, int $value): ReadExpectation`
- `shouldWrite(int $address, int|string $value): WriteExpectation`
- `shouldWriteLong(int $address, int|string $value): WriteExpectation`
- `shouldWriteWord(int $address, int|string $value): WriteExpectation`
- `shouldWriteByte(int $address, int|string $value): WriteExpectation`
- `shouldWriteString(int $address, string $value): StringWriteExpectation`
- `shouldReadFrom(string $name, int $value): ReadExpectation`
- `shouldWriteTo(string $name, int $value): WriteExpectation`
- `alloc(int $size): int`
- `call(string $name): self`
- `with(int|float|WildcardArgument ...$arguments): self`
- `shouldReturn(int|float $value): self`
- `forceStop(): void`
- `rellocate(string $name, int $address): void`
- `run(): void`
- `initUint(int $address, int $value, int $size): void`
- `initUint8(int $address, int $value): void`
- `initUint16(int $address, int $value): void`
- `initUint32(int $address, int $value): void`
- `setSize(string $name, int $size): int`
- `addressOf(string $name): int`
- `allocString(string $str): int`

## CallExpectation Class

The `CallExpectation` class allows you to define expectations for function calls within your test cases. Here are some of the methods available:

- `with(int|float|string|WildcardArgument|LocalArgument ...$parameters): self`
- `andReturn(int|float $value): self`
- `do(\Closure $callback): self`

By using these methods, you can create detailed and precise tests for SH4 object files to ensure they behave as expected during decompilation.