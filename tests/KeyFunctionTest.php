<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

final class KeyFunctionTest extends TestCase
{
    public function testKeyIsCalledExactlyOncePerElement(): void
    {
        foreach (Comparison::cases() as $comparison) {
            foreach ([0, 1, 2, 16, 17, 100, 5000] as $n) {
                foreach ([false, true] as $preserve) {
                    $calls = 0;
                    $sorter = new Sorter(Order::Descending, $comparison, function ($v) use (&$calls) {
                        ++$calls;
                        return $v % 17;
                    }, $preserve);
                    $sorter->sort($n === 0 ? [] : range(1, $n));
                    $this->assertSame($n, $calls, "{$comparison->name} n=$n preserve=" . (int) $preserve);
                }
            }
        }
    }

    public function testKeyReceivesTheValueNotTheKey(): void
    {
        $sorter = new Sorter(key: fn(string $v) => strlen($v), preserveKeys: true);
        $this->assertSame(['x' => 'a', 'z' => 'bb', 'y' => 'ccc'], $sorter->sort(['y' => 'ccc', 'x' => 'a', 'z' => 'bb']));
    }

    /** Reference: lexicographic comparison, the shorter array first when it is a prefix. */
    private static function lexicographic(array $a, array $b): int
    {
        $a = array_values($a);
        $b = array_values($b);
        foreach ($a as $i => $v) {
            if (!array_key_exists($i, $b)) {
                return 1;
            }
            if (($c = $v <=> $b[$i]) !== 0) {
                return $c;
            }
        }
        return count($a) <=> count($b);
    }

    public function testArrayKeysCompareColumnByColumn(): void
    {
        // PHP's own comparison of arrays would put [9] before [1, 2] (fewer elements first).
        $rows = [[1, 2], [9], [1, 1, 1], [1], [], [0, 5, 5], [1, 2]];
        $sorted = (new Sorter(key: fn($r) => $r))->sort($rows);
        $this->assertSame([[], [0, 5, 5], [1], [1, 1, 1], [1, 2], [1, 2], [9]], $sorted);
    }

    public function testArrayKeysAgainstReference(): void
    {
        foreach ([Order::Ascending, Order::Descending] as $order) {
            foreach ([1, 2, 17, 18, 200, 1500] as $n) {
                for ($seed = 0; $seed < 6; ++$seed) {
                    mt_srand($seed * 31 + $n);
                    $rows = [];
                    for ($i = 0; $i < $n; ++$i) {
                        $row = [];
                        for ($c = mt_rand(0, 3); $c > 0; --$c) {
                            $row[] = mt_rand(0, 3);
                        }
                        $rows[] = ['key' => $row, 'id' => $i];
                    }
                    $expected = $rows;
                    // usort() is stable, so ties keep their order, in both directions.
                    usort($expected, fn($x, $y) => $order === Order::Ascending
                        ? self::lexicographic($x['key'], $y['key'])
                        : self::lexicographic($y['key'], $x['key']));
                    $sorted = (new Sorter($order, key: fn($r) => $r['key']))->sort($rows);
                    $this->assertSame($expected, $sorted, "{$order->name} n=$n seed=$seed");
                }
            }
        }
    }

    public function testArrayKeysWithStringsAndComparison(): void
    {
        $rows = [['b', '10'], ['a', '9'], ['b', '9'], ['a', '10']];
        $natural = (new Sorter(comparison: Comparison::Natural, key: fn($r) => $r))->sort($rows);
        $this->assertSame([['a', '9'], ['a', '10'], ['b', '9'], ['b', '10']], $natural);
        $binary = (new Sorter(comparison: Comparison::Binary, key: fn($r) => $r))->sort($rows);
        $this->assertSame([['a', '10'], ['a', '9'], ['b', '10'], ['b', '9']], $binary);
    }

    public function testSortKeysAppliesTheKeyFunctionToTheArrayKey(): void
    {
        $sorter = new Sorter(key: fn(string $k) => strrev($k));
        $this->assertSame(['ba' => 1, 'ab' => 2, 'cb' => 3], $sorter->sortKeys(['ab' => 2, 'cb' => 3, 'ba' => 1]));
    }
}
