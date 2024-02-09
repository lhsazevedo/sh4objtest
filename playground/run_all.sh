./sh4objtest.php "playground/0001_nop.php"

# TODO: Migrate to "shouldCall" expectations
# ./sh4objtest.php "playground/0002_call.php"
# ./sh4objtest.php "playground/0003_two_calls.php"

./sh4objtest "playground/add/add.php"
./sh4objtest "playground/calls/stack_params/main.php"
./sh4objtest "playground/calls/with_int_return_int/main.php"
./sh4objtest "playground/calls/with_int_return_int/main.php"
./sh4objtest "playground/global_struct/offset/main.php"
./sh4objtest "playground/global_struct/offset_write/main.php"
./sh4objtest "playground/global_struct/pointer_offset/main.php"
./sh4objtest "playground/operators/comparison/main.php"

./sh4objtest playground/tbg/title/main.php playground/tbg/title/main.obj

./sh4objtest playground/tbg/_012100_8c012f44/main.php playground/tbg/_012100_8c012f44/main_c.obj
./sh4objtest playground/tbg/_012100_8c012f44/main.php playground/tbg/_012100_8c012f44/main_src.obj
