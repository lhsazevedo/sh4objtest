ASMSH_FLAGS="-cpu=sh4 -endian=little -sjis"
wine "$SHC_BIN/asmsh.exe" "main.src" -object="main_src.obj" $ASMSH_FLAGS

wine "$SHC_BIN/shc.exe" "main.c" -object="main_c.obj" -sub=../../shc.sub
wine "$SHC_BIN/shc.exe" "main.c" -c=a -object="main_c.src" -sub=../../shc.sub
