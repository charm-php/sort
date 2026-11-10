<?php

declare(strict_types=1);

// The code under test lives in included files: the main script is never cached by OPcache,
// so the JIT would not compile anything in it.

namespace Charm\Sort\Bench;

use Charm\Sort\Comparison;
use Charm\Sort\Loops;
use Charm\Sort\Sorter;

/**
 * A drop-in replacement for PHP's sort(), built on the same loops as Sorter, to measure what
 * a function in place of a returning object costs (nothing measurable, see the README).
 */
function sort(array &$a, int $flags = SORT_REGULAR): bool
{
    if ($flags === SORT_REGULAR) {
        if (!array_is_list($a)) {
            $a = array_values($a);
        }
        Loops::ascRegular($a, false);
        return true;
    }
    $comparison = match ($flags) {
        SORT_STRING => Comparison::Binary,
        SORT_STRING | SORT_FLAG_CASE => Comparison::BinaryCaseInsensitive,
        SORT_NUMERIC => Comparison::Numeric,
        SORT_NATURAL => Comparison::Natural,
        SORT_NATURAL | SORT_FLAG_CASE => Comparison::NaturalCaseInsensitive,
        default => null, // SORT_LOCALE_STRING: process-global locale, not supported here
    };
    if ($comparison === null) {
        return \sort($a, $flags);
    }
    $a = (new Sorter($comparison))->sort($a);
    return true;
}

// The three contestants, all as array -> sorted array so the timing loop treats them alike.

function nativeSort(array $a): array
{
    \sort($a);
    return $a;
}

function sorterSort(array $a): array
{
    static $sorter = new Sorter();
    return $sorter->sort($a);
}

function replicaSort(array $a): array
{
    sort($a);
    return $a;
}

function nativeKsort(array $a): array
{
    \ksort($a);
    return $a;
}

function sorterSortKeys(array $a): array
{
    static $sorter = new Sorter();
    return $sorter->sortKeys($a);
}

// With a comparison function: native usort()/uasort()/uksort() against the same through Sorter.

function nativeUsort(array $a): array
{
    \usort($a, static fn($x, $y) => $x <=> $y);
    return $a;
}

function sorterCompare(array $a): array
{
    static $sorter = new Sorter(static fn($x, $y) => $x <=> $y);
    return $sorter->sort($a);
}

function nativeUasort(array $a): array
{
    \uasort($a, static fn($x, $y) => $x <=> $y);
    return $a;
}

function sorterCompareKeep(array $a): array
{
    static $sorter = new Sorter(static fn($x, $y) => $x <=> $y, preserveKeys: true);
    return $sorter->sort($a);
}

function nativeUksort(array $a): array
{
    \uksort($a, static fn($x, $y) => $x <=> $y);
    return $a;
}

function sorterCompareKeys(array $a): array
{
    static $sorter = new Sorter(static fn($x, $y) => $x <=> $y);
    return $sorter->sortKeys($a);
}

/**
 * Time $fn on $input: $runs timed runs of $reps calls each, after one untimed warm-up run.
 *
 * @return array{best: float, median: float, last: array} milliseconds per call, and the last result
 */
function measure(callable $fn, array $input, int $reps, int $runs): array
{
    $times = [];
    $out = [];
    for ($r = 0; $r <= $runs; ++$r) {
        $t = hrtime(true);
        for ($k = 0; $k < $reps; ++$k) {
            $out = $fn($input);
        }
        $dt = (hrtime(true) - $t) / 1e6 / $reps;
        if ($r > 0) {
            $times[] = $dt;
        }
    }
    \sort($times);
    return ['best' => $times[0], 'median' => $times[intdiv(\count($times), 2)], 'last' => $out];
}
