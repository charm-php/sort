<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

/** compare = usort(), with preserveKeys = uasort(), and sortKeys() with compare = uksort(). */
final class ComparatorTest extends TestCase
{
    /** @return iterable<string, array{Closure(mixed, mixed): int, callable(int): array}> */
    public static function comparators(): iterable
    {
        $spaceship = fn($a, $b) => $a <=> $b;
        yield 'ints with <=>' => [$spaceship, fn(int $n) => array_map(fn() => mt_rand(0, intdiv($n, 2) + 1), array_fill(0, $n, 0))];
        yield 'ints, difference' => [fn($a, $b) => $a - $b, fn(int $n) => array_map(fn() => mt_rand(-1000, 1000), array_fill(0, $n, 0))];
        yield 'strings with strcmp' => [strcmp(...), fn(int $n) => array_map(fn() => Data::word('abc', 0, 3), array_fill(0, $n, 0))];
        yield 'rows by one field' => [
            fn($a, $b) => $a['g'] <=> $b['g'],
            function (int $n) {
                $rows = [];
                for ($i = 0; $i < $n; ++$i) {
                    $rows[] = ['g' => mt_rand(0, 6), 'id' => $i];
                }
                return $rows;
            },
        ];
        yield 'sorted' => [$spaceship, fn(int $n) => range(0, $n - 1)];
        yield 'reversed' => [$spaceship, fn(int $n) => $n ? range($n - 1, 0) : []];
        yield 'all equal' => [$spaceship, fn(int $n) => array_fill(0, $n, 3)];
        // Equal when they differ by less than 2 in the tens: a comparator with many ties, which must
        // still be stable. Not a total preorder in general, but the buckets keep it consistent.
        yield 'ties from a bucket function' => [fn($a, $b) => intdiv($a, 10) <=> intdiv($b, 10), fn(int $n) => array_map(fn() => mt_rand(0, 99), array_fill(0, $n, 0))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('comparators')]
    public function testEqualsUsortUasortAndUksort(\Closure $cmp, callable $make): void
    {
        foreach ([Order::Ascending, Order::Descending] as $order) {
            $native = $order === Order::Ascending ? $cmp : fn($a, $b) => $cmp($b, $a);
            foreach (Data::SIZES as $n) {
                for ($seed = 1; $seed <= 3; ++$seed) {
                    mt_srand($seed * 100 + $n);
                    $values = $make($n);
                    $label = "{$order->name} n=$n seed=$seed";

                    $expected = $values;
                    usort($expected, $native);
                    $this->assertSame(serialize($expected), serialize((new Sorter($order, compare: $cmp))->sort($values)), "usort $label");

                    $keyed = Data::withStringKeys($values);
                    $expected = $keyed;
                    uasort($expected, $native);
                    $this->assertSame(serialize($expected), serialize((new Sorter($order, compare: $cmp, preserveKeys: true))->sort($keyed)), "uasort $label");

                    // uksort(): the comparison function gets the keys. Use the values as numeric keys.
                    if (is_int($values[0] ?? null)) {
                        $byKey = array_flip($values);
                        $expected = $byKey;
                        uksort($expected, $native);
                        $this->assertSame(serialize($expected), serialize((new Sorter($order, compare: $cmp))->sortKeys($byKey)), "uksort $label");
                    }
                }
            }
        }
    }

    public function testComparatorSeesKeysInSortKeys(): void
    {
        $sorter = new Sorter(compare: fn(string $a, string $b) => strlen($a) <=> strlen($b));
        $this->assertSame(['b' => 1, 'aa' => 2, 'ccc' => 3], $sorter->sortKeys(['ccc' => 3, 'aa' => 2, 'b' => 1]));
    }

    public function testDescendingNegatesAndStaysStable(): void
    {
        $rows = [['g' => 1, 'id' => 0], ['g' => 2, 'id' => 1], ['g' => 1, 'id' => 2], ['g' => 2, 'id' => 3]];
        $sorted = (new Sorter(Order::Descending, compare: fn($a, $b) => $a['g'] <=> $b['g']))->sort($rows);
        // Reversing the ascending result would give ids 3, 1, 2, 0.
        $this->assertSame([1, 3, 0, 2], array_column($sorted, 'id'));
    }

    public function testFloatResultsAreTruncatedLikeUsort(): void
    {
        $cmp = fn($a, $b) => ($a <=> $b) * 0.5; // always truncates to 0: every pair is "equal"
        mt_srand(3);
        $values = array_map(fn() => mt_rand(0, 30), array_fill(0, 200, 0));
        $expected = $values;
        usort($expected, $cmp);
        $this->assertSame($expected, (new Sorter(compare: $cmp))->sort($values));
    }

    public function testBoolResultIsRejected(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('must return an int, bool returned');
        (new Sorter(compare: fn($a, $b) => $a > $b))->sort([3, 1, 2]);
    }

    public function testBoolResultIsRejectedInTheMergePassesToo(): void
    {
        $this->expectException(\TypeError::class);
        // Ints for the insertion runs, then a bool only from the merge's first comparison.
        $calls = 0;
        (new Sorter(compare: function ($a, $b) use (&$calls) {
            return ++$calls > 200 ? true : $a <=> $b;
        }))->sort(range(100, 1));
    }

    public function testCannotBeCombinedWithKeyOrComparison(): void
    {
        foreach ([
            fn() => new Sorter(key: fn($v) => $v, compare: fn($a, $b) => $a <=> $b),
            fn() => new Sorter(comparison: Comparison::Binary, compare: fn($a, $b) => $a <=> $b),
            fn() => new Sorter(comparison: Comparison::Numeric, compare: fn($a, $b) => $a <=> $b),
        ] as $make) {
            try {
                $make();
                $this->fail('expected a ValueError');
            } catch (\ValueError $e) {
                $this->assertSame('compare cannot be combined with key or comparison', $e->getMessage());
            }
        }
        $this->assertInstanceOf(Sorter::class, new Sorter(comparison: Comparison::Regular, compare: fn($a, $b) => $a <=> $b));
    }

    public function testExceptionsPropagateAndSorterStaysUsable(): void
    {
        $sorter = new Sorter(compare: function ($a, $b) {
            if ($a === 13 || $b === 13) {
                throw new \RuntimeException('unlucky');
            }
            return $a <=> $b;
        });
        try {
            $sorter->sort([5, 13, 2, 8]);
            $this->fail('expected an exception');
        } catch (\RuntimeException) {
        }
        $this->assertSame([2, 5, 8], $sorter->sort([5, 2, 8]));
    }
}
