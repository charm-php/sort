<?php

declare(strict_types=1);

// Sorter->sort() and a drop-in sort() replica against native sort(), on every data set and size.
// Every row is checked: the output must be identical to sort()'s (serialize(), so it also proves
// stability), otherwise the run fails.
//
//   bench/run.sh [sizes] [runs]        both configurations, JIT and no JIT (what the README quotes)
//   php -d ... bench/run.php [sizes] [runs]   one configuration; sizes like 10,100,1000,100000,1000000
//
// The JIT configuration is
//   -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 -d opcache.file_update_protection=0
// and the run proves that the JIT compiled something by watching the JIT buffer shrink.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/functions.php';

use function Charm\Sort\Bench\measure;
use function Charm\Sort\Bench\nativeKsort;
use function Charm\Sort\Bench\nativeSort;
use function Charm\Sort\Bench\replicaSort;
use function Charm\Sort\Bench\sorterSort;
use function Charm\Sort\Bench\sorterSortKeys;

$sizes = array_map('intval', explode(',', $argv[1] ?? '10,100,1000,10000,100000,1000000'));
$runs = (int) ($argv[2] ?? 7);

mt_srand(42);
$datasets = [
    'ints, many duplicates' => fn(int $n) => array_map(fn() => mt_rand(0, intdiv($n, 2)), range(1, $n)),
    'ints, full range' => fn(int $n) => array_map(fn() => mt_rand(), range(1, $n)),
    'floats' => fn(int $n) => array_map(fn() => mt_rand() / mt_getrandmax() * 1000 - 500, range(1, $n)),
    // Random bytes that happen to be numeric (" 42") compare numerically, the rest bytewise; that is not
    // transitive, so several orders are valid. They are left out so the output has one correct answer.
    'binary strings 1-20 B' => fn(int $n) => array_map(function () {
        do {
            $s = random_bytes(mt_rand(1, 20));
        } while (is_numeric($s));
        return $s;
    }, range(1, $n)),
    'already sorted ints' => fn(int $n) => range(1, $n),
    'reversed ints' => fn(int $n) => range($n, 1),
    // Stability probes: equal by <, == and sort(), but distinguishable.
    'numeric strings (stability)' => fn(int $n) => array_map(
        fn() => [(string) ($v = mt_rand(0, 999)), "$v.0", "0$v", "{$v}e0"][mt_rand(0, 3)],
        range(1, $n),
    ),
    'floats with -0.0 (stability)' => fn(int $n) => array_map(
        fn() => mt_rand(0, 9) === 0 ? [0.0, -0.0][mt_rand(0, 1)] : (float) mt_rand(-5, 5),
        range(1, $n),
    ),
];
$impls = ['Sorter->sort()' => 'sorterSort', 'sort() replica' => 'replicaSort'];

$jitOn = function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false);
$free = fn() => opcache_get_status(false)['jit']['buffer_free'];
printf("PHP %s, JIT %s, best/median of %d runs after 1 warm-up run\n", PHP_VERSION, $jitOn ? 'on (opcache.jit=' . ini_get('opcache.jit') . ')' : 'off', $runs);

// Warm up every contestant on every data type, so compilation does not land in a measurement.
$freeBefore = $jitOn ? $free() : 0;
foreach ($datasets as $make) {
    $warm = $make(20000);
    foreach (['nativeSort', 'sorterSort', 'replicaSort'] as $fn) {
        measure("Charm\\Sort\\Bench\\$fn", $warm, 1, 3);
    }
}
$warm = array_map(fn() => mt_rand(0, 20000), range(1, 20000));
foreach (['nativeUsort', 'sorterCompare', 'nativeUasort', 'sorterCompareKeep'] as $fn) {
    measure("Charm\\Sort\\Bench\\$fn", $warm, 1, 3);
}
foreach (['nativeUsortLower', 'sorterByLower'] as $fn) {
    measure("Charm\\Sort\\Bench\\$fn", array_map(fn($i) => 'a' . ($i * 7919 % 1000), range(1, 5000)), 1, 3);
}
foreach (['nativeUksort', 'sorterCompareKeys'] as $fn) {
    measure("Charm\\Sort\\Bench\\$fn", array_flip($warm), 1, 3);
}
foreach ([array_flip(range(0, 20000)), array_flip(array_map(fn($i) => "k$i", range(0, 20000)))] as $warm) {
    $keys = array_keys($warm);
    shuffle($keys);
    $warm = array_flip($keys);
    measure(nativeKsort(...), $warm, 1, 3);
    measure(sorterSortKeys(...), $warm, 1, 3);
}
if ($jitOn) {
    $used = $freeBefore - $free();
    printf("JIT buffer used by the warm-up: %d KB%s\n", $used / 1024, $used > 0 ? '' : "  WARNING: nothing was compiled, the numbers below are interpreted");
    if ($used <= 0) {
        exit(1);
    }
}

$failures = 0;
foreach ($sizes as $n) {
    // Short sorts are repeated so a timed run lasts long enough for hrtime().
    $reps = max(1, intdiv(200000, $n));
    $nRuns = $n >= 1000000 ? min($runs, 3) : $runs;
    printf("\nn = %s\n%-31s %10s %10s %10s   %s   %s\n", number_format($n), 'data (ms per sort)', 'sort()', 'Sorter', 'replica', 'Sorter/replica speed vs sort(), best | median', 'check');
    foreach ($datasets as $name => $make) {
        $input = $make($n);
        $expected = $input;
        sort($expected);
        $expected = serialize($expected);

        $native = measure(nativeSort(...), $input, $reps, $nRuns);
        $row = [];
        $check = 'identical';
        foreach ($impls as $label => $fn) {
            $m = measure("Charm\\Sort\\Bench\\$fn", $input, $reps, $nRuns);
            $row[$label] = $m;
            if (serialize($m['last']) !== $expected) {
                $check = "DIFFERENT OUTPUT ($label)";
                ++$failures;
            }
        }
        [$s, $r] = array_values($row);
        printf(
            "%-31s %10.4f %10.4f %10.4f   %5.2fx %5.2fx | %5.2fx %5.2fx   %s\n",
            $name, $native['best'], $s['best'], $r['best'],
            $native['best'] / $s['best'], $native['best'] / $r['best'],
            $native['median'] / $s['median'], $native['median'] / $r['median'],
            $check,
        );
    }
}
// Sorting by key: the result is a new array in key order, so Sorter's rebuild of the array is included.
$keySets = [
    'int keys' => function (int $n) {
        $keys = range(0, $n * 3, 3);
        shuffle($keys);
        return array_combine(array_slice($keys, 0, $n), range(1, $n));
    },
    'string keys (non-numeric)' => function (int $n) {
        $a = [];
        while (count($a) < $n) {
            $a['k' . bin2hex(random_bytes(mt_rand(1, 6)))] = count($a);
        }
        return $a;
    },
];
foreach ($sizes as $n) {
    $reps = max(1, intdiv(200000, $n));
    $nRuns = $n >= 1000000 ? min($runs, 3) : $runs;
    printf("\nsortKeys(), n = %s\n%-31s %10s %10s   %s   %s\n", number_format($n), 'data (ms per sort)', 'ksort()', 'Sorter', 'speed vs ksort(), best | median', 'check');
    foreach ($keySets as $name => $make) {
        $input = $make($n);
        $expected = $input;
        ksort($expected);
        $native = measure(nativeKsort(...), $input, $reps, $nRuns);
        $mine = measure(sorterSortKeys(...), $input, $reps, $nRuns);
        $same = serialize($mine['last']) === serialize($expected);
        $failures += $same ? 0 : 1;
        printf("%-31s %10.4f %10.4f   %5.2fx | %5.2fx   %s\n", $name, $native['best'], $mine['best'],
            $native['best'] / $mine['best'], $native['median'] / $mine['median'], $same ? 'identical' : 'DIFFERENT OUTPUT');
    }
}
// Comparison functions: the same `$a <=> $b` closure for both sides, random ints with many duplicates.
$compareRows = [
    'usort()  / compare' => ['Charm\\Sort\\Bench\\nativeUsort', 'Charm\\Sort\\Bench\\sorterCompare', fn(int $n) => array_map(fn() => mt_rand(0, $n), range(1, $n))],
    'uasort() / preserveKeys' => ['Charm\\Sort\\Bench\\nativeUasort', 'Charm\\Sort\\Bench\\sorterCompareKeep', fn(int $n) => array_map(fn() => mt_rand(0, $n), range(1, $n))],
    'uksort() / sortKeys()' => ['Charm\\Sort\\Bench\\nativeUksort', 'Charm\\Sort\\Bench\\sorterCompareKeys', function (int $n) {
        $keys = range(0, $n - 1);
        shuffle($keys);
        return array_flip($keys);
    }],
    'usort(strtolower) / by()' => ['Charm\\Sort\\Bench\\nativeUsortLower', 'Charm\\Sort\\Bench\\sorterByLower', fn(int $n) => array_map(function () {
        $s = '';
        for ($i = mt_rand(3, 12); $i > 0; --$i) {
            $s .= 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'[mt_rand(0, 51)];
        }
        return $s;
    }, range(1, $n))],
    'usort(), already sorted' => ['Charm\\Sort\\Bench\\nativeUsort', 'Charm\\Sort\\Bench\\sorterCompare', fn(int $n) => range(1, $n)],
];
foreach ($sizes as $n) {
    $reps = max(1, intdiv(100000, $n));
    $nRuns = $n >= 1000000 ? min($runs, 3) : $runs;
    printf("\ncomparison function, n = %s\n%-31s %10s %10s   %s   %s\n", number_format($n), 'native / Sorter (ms per sort)', 'native', 'Sorter', 'speed vs native, best | median', 'check');
    foreach ($compareRows as $name => [$native, $mine, $make]) {
        $input = $make($n);
        $a = measure($native, $input, $reps, $nRuns);
        $b = measure($mine, $input, $reps, $nRuns);
        $same = serialize($a['last']) === serialize($b['last']);
        $failures += $same ? 0 : 1;
        printf("%-31s %10.4f %10.4f   %5.2fx | %5.2fx   %s\n", $name, $a['best'], $b['best'],
            $a['best'] / $b['best'], $a['median'] / $b['median'], $same ? 'identical' : 'DIFFERENT OUTPUT');
    }
}
exit($failures === 0 ? 0 : 1);
