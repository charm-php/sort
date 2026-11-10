#!/bin/sh
# Runs the benchmark twice: with the tracing JIT and without any JIT.
#   bench/run.sh [sizes] [runs]      e.g. bench/run.sh 10,100,1000,100000,1000000 7
cd "$(dirname "$0")/.." || exit 1
echo "=== tracing JIT ==="
php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 -d opcache.file_update_protection=0 bench/run.php "$@" || exit 1
echo
echo "=== no JIT ==="
php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=0 -d opcache.file_update_protection=0 bench/run.php "$@"
