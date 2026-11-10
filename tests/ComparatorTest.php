<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

/** using() = usort(), with preserveKeys = uasort(), and sortKeys() = uksort(). */
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
                    $this->assertSame(serialize($expected), serialize(Sorter::using($cmp, $order)->sort($values)), "usort $label");

                    $keyed = Data::withStringKeys($values);
                    $expected = $keyed;
                    uasort($expected, $native);
                    $this->assertSame(serialize($expected), serialize(Sorter::using($cmp, $order, true)->sort($keyed)), "uasort $label");

                    // uksort(): the comparison function gets the keys. Use the values as numeric keys.
                    if (is_int($values[0] ?? null)) {
                        $byKey = array_flip($values);
                        $expected = $byKey;
                        uksort($expected, $native);
                        $this->assertSame(serialize($expected), serialize(Sorter::using($cmp, $order)->sortKeys($byKey)), "uksort $label");
                    }
                }
            }
        }
    }

    public function testComparatorSeesKeysInSortKeys(): void
    {
        $sorter = Sorter::using(fn(string $a, string $b) => strlen($a) <=> strlen($b));
        $this->assertSame(['b' => 1, 'aa' => 2, 'ccc' => 3], $sorter->sortKeys(['ccc' => 3, 'aa' => 2, 'b' => 1]));
    }

    public function testUksortWithStringKeys(): void
    {
        foreach ([Order::Ascending, Order::Descending] as $order) {
            mt_srand(8);
            $input = [];
            while (count($input) < 200) {
                $input[Data::word('abcd', 1, 4)] = count($input);
            }
            $expected = $input;
            uksort($expected, $order === Order::Ascending ? strcmp(...) : fn($a, $b) => strcmp($b, $a));
            $this->assertSame(serialize($expected), serialize(Sorter::using(strcmp(...), $order)->sortKeys($input)));
        }
    }

    public function testDescendingNegatesAndStaysStable(): void
    {
        $rows = [['g' => 1, 'id' => 0], ['g' => 2, 'id' => 1], ['g' => 1, 'id' => 2], ['g' => 2, 'id' => 3]];
        $sorted = Sorter::using(fn($a, $b) => $a['g'] <=> $b['g'], Order::Descending)->sort($rows);
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
        $this->assertSame($expected, Sorter::using($cmp)->sort($values));
    }

    public function testBoolResultIsRejected(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('must return an int, bool returned');
        Sorter::using(fn($a, $b) => $a > $b)->sort([3, 1, 2]);
    }

    public function testBoolResultIsRejectedInTheMergePassesToo(): void
    {
        $this->expectException(\TypeError::class);
        // Ints for the insertion runs, then a bool only from the merge's first comparison.
        $calls = 0;
        Sorter::using(function ($a, $b) use (&$calls) {
            return ++$calls > 200 ? true : $a <=> $b;
        })->sort(range(100, 1));
    }

    /** The one-liners the README shows instead of dedicated constructors. */
    public function testVersionCompare(): void
    {
        $this->assertSame(['1.0.0-beta', '1.0.0', '1.9', '1.10'], (Sorter::using(version_compare(...)))->sort(['1.10', '1.9', '1.0.0', '1.0.0-beta']));
        $parts = ['0', '1', '2', '10', '1.0', '1.0.0', '2.1', '1.0.0-beta', '1.0-rc1', '1.0RC2', '1.0pl1', '1.0-dev', '1.0alpha'];
        foreach ([Order::Ascending, Order::Descending] as $order) {
            foreach ([2, 17, 100, 500] as $n) {
                mt_srand($n);
                $input = [];
                for ($i = 0; $i < $n; ++$i) {
                    $input[] = $parts[mt_rand(0, count($parts) - 1)] . ($i % 3 === 0 ? '' : '.' . mt_rand(0, 12));
                }
                $expected = $input;
                usort($expected, fn($a, $b) => $order === Order::Ascending ? version_compare($a, $b) : version_compare($b, $a));
                $this->assertSame($expected, Sorter::using(version_compare(...), $order)->sort($input), "{$order->name} n=$n");
            }
        }
    }

    public function testMultipleColumnsWithAnArrayComparison(): void
    {
        foreach ([0, 1, 2, 17, 18, 300, 2000] as $n) {
            for ($seed = 0; $seed < 4; ++$seed) {
                mt_srand($n * 13 + $seed);
                $rows = [];
                for ($i = 0; $i < $n; ++$i) {
                    $rows[] = ['last' => Data::word('xyz', 1, 2), 'first' => Data::word('ab', 1, 2), 'age' => mt_rand(0, 3), 'id' => $i];
                }
                // Age descending (the operands swapped), then last name, then first name ascending.
                $cmp = fn($a, $b) => [$b['age'], $a['last'], $a['first']] <=> [$a['age'], $b['last'], $b['first']];
                $expected = $rows;
                usort($expected, $cmp);
                $this->assertSame($expected, Sorter::using($cmp)->sort($rows), "n=$n seed=$seed");
            }
        }
    }

    public function testExceptionsPropagateAndSorterStaysUsable(): void
    {
        $sorter = Sorter::using(function ($a, $b) {
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
