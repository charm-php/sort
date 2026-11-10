<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

final class SorterTest extends TestCase
{
    public function testDefaults(): void
    {
        $sorter = new Sorter();
        $this->assertSame(Order::Ascending, $sorter->order);
        $this->assertSame(Comparison::Regular, $sorter->comparison);
        $this->assertNull($sorter->key);
        $this->assertFalse($sorter->preserveKeys);
        $this->assertSame([1, 2, 3], $sorter->sort([3, 1, 2]));
        $this->assertSame([3, 2, 1], (new Sorter(Order::Descending))->sort([3, 1, 2]));
    }

    public function testEmptyAndSingle(): void
    {
        foreach (Comparison::cases() as $c) {
            $s = new Sorter(comparison: $c, key: fn($v) => $v);
            $this->assertSame([], $s->sort([]));
            $this->assertSame([5], $s->sort([5]));
            $this->assertSame([5], $s->sort(['x' => 5]));
            $this->assertSame(['x' => 5], (new Sorter(comparison: $c, preserveKeys: true))->sort(['x' => 5]));
            $this->assertSame([], $s->sortKeys([]));
        }
    }

    public function testNonListInputIsReindexedUnlessKeysArePreserved(): void
    {
        $input = [5 => 'b', 'x' => 'c', 2 => 'a'];
        $this->assertSame(['a', 'b', 'c'], (new Sorter())->sort($input));
        $this->assertSame([2 => 'a', 5 => 'b', 'x' => 'c'], (new Sorter(preserveKeys: true))->sort($input));
        $this->assertSame(['x' => 'c', 5 => 'b', 2 => 'a'], (new Sorter(Order::Descending, preserveKeys: true))->sort($input));
    }

    public function testInputIsNotModifiedAndNewArrayIsReturned(): void
    {
        $input = [3, 1, 2];
        $copy = $input;
        (new Sorter())->sort($input);
        (new Sorter())->sortKeys($input);
        $this->assertSame($copy, $input);
    }

    public function testSortsValuesOfAnyTypeWithTheRegularComparison(): void
    {
        $this->assertSame([-1, 0.5, 2, '3', 10], (new Sorter())->sort([10, '3', 0.5, -1, 2]));
        $this->assertSame([false, true], (new Sorter())->sort([true, false]));
    }

    public function testComparisonsDiffer(): void
    {
        $input = ['10', '9', 'a1', 'A2', 'a10'];
        $this->assertSame(['9', '10', 'A2', 'a1', 'a10'], (new Sorter(comparison: Comparison::Natural))->sort($input));
        $this->assertSame(['10', '9', 'A2', 'a1', 'a10'], (new Sorter(comparison: Comparison::Binary))->sort($input));
        $this->assertSame(['10', '9', 'a1', 'a10', 'A2'], (new Sorter(comparison: Comparison::BinaryCaseInsensitive))->sort($input));
        $this->assertSame(['9', '10', 'a1', 'A2', 'a10'], (new Sorter(comparison: Comparison::NaturalCaseInsensitive))->sort($input));
        $this->assertSame(['a1', 'A2', 'a10', '9', '10'], (new Sorter(comparison: Comparison::Numeric))->sort(['a1', 'A2', 'a10', '9', '10']));
    }

    public function testEveryLoopHandlesStringsLargerThanTheCutoff(): void
    {
        // Binary comparison of numeric-looking strings must not compare numerically.
        $input = array_map(fn($i) => (string) (($i * 7919) % 1009), range(1, 500));
        $expected = $input;
        sort($expected, SORT_STRING);
        $this->assertSame($expected, (new Sorter(comparison: Comparison::Binary))->sort($input));
    }
}
