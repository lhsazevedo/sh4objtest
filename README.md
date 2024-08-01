# Hitachi SH4 Object Tester

The Hitachi SH4 Object Tester is a specialized simulator and testing framework developed to support
the [Tokyo Bus Guide decompilation project](https://github.com/lhsazevedo/tokyo-bus-guide-decomp).
This tool enables developers to run and validate SH4 object files using a suite of predefined
expectations and test cases.

> [!NOTE]  
> This project is in active development and may contain breaking changes.

## Features

- Simulates Hitachi SH4 object code execution
- Provides a framework for writing and running test cases
- Supports memory allocation and initialization
- Allows mocking of function calls and return values
- Enables verification of memory writes and function calls

## Usage

To use the Hitachi SH4 Object Tester, you need to write test cases that define the initial state,
expected behavior, and assertions for your SH4 object code. Let's say that we want to test the
following function:

```c
void functionUnderTest(int* ptr) {
    int value = *ptr;
    int result = someFunction(value);
    *ptr = result;
}
```

This C function reads a value from the memory location pointed to by `ptr`, calls `someFunction`
with that value, and then writes the result back to the same memory location.

Here's a basic example of how to structure a test case for this function:

```php
# example_test.php

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    public function test_exampleFunction() {
        // Set up initial memory state
        $someAddress = $this->alloc(4);
        $this->initUint32($someAddress, 0x1234);

        // Define expected function calls
        $this->shouldCall('_someFunction')
            ->with(0x1234)
            ->andReturn(0x5678);

        // Define expected memory writes
        $this->shouldWrite($someAddress, 0x5678);

        // Run the function under test
        $this->call('_functionUnderTest')
            ->with($someAddress)
            ->run();
    }
};
```

This example demonstrates how to:
1. Allocate and initialize memory
2. Set expectations for function calls
3. Set expectations for memory writes
4. Run the function under test

Note that all expectations are defined before calling `run()`. There are no assertions after the
function execution.

To run the test case, you need to execute the following command:

```sh
sh4objtest example_test.php path/to/your/object/file.o
```

Sh4objtest will load the object file, execute the test case, and report the results:
```sh
test_exampleFunction...
✔ Fulfilled: Called _someFunction
✔ Fulfilled: Wrote 0x5678 to 0x800000

[ PASS ] 2 expectations fulfilled
```

## Documentation

Comprehensive documentation for this project is still in development. In the meantime, please refer
to the test cases in the [Tokyo Bus Guide decompilation
project](https://github.com/lhsazevedo/tokyo-bus-guide-decomp/tree/main/tests) for practical
examples of how to write tests using this framework.
