<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The output must be identical to PHP's sort functions, compared with serialize() so that
 * 0.0 differs from -0.0 and "7" from "7.0": that is what proves the sort is stable.
 */
final class DifferentialTest extends TestCase
{
    public static function configurations(): iterable
    {
        foreach (Comparison::cases() as $comparison) {
            foreach (Order::cases() as $order) {
                foreach ([false, true] as $preserve) {
                    yield "{$comparison->name} {$order->name}" . ($preserve ? ' preserveKeys' : '') => [$comparison, $order, $preserve];
                }
            }
        }
    }

    /** sort()/rsort()/asort()/arsort() on every data set and size, 3 seeds each. */
    #[DataProvider('configurations')]
    public function testEqualsNativeSort(Comparison $comparison, Order $order, bool $preserve): void
    {
        $sorter = Sorter::create($comparison, $order, $preserve);
        // Same configuration through the general path (key function, offsets carried along).
        $keyed = Sorter::by(fn($v) => $v, $comparison, $order, $preserve);
        $flags = Data::flags($comparison);
        $cases = 0;
        foreach ([1, 2, 3] as $seed) {
            foreach (Data::sets() as $name => $make) {
                foreach (Data::SIZES as $n) {
                    mt_srand($seed * 1000 + $n);
                    $input = $make($n);
                    if ($preserve) {
                        $input = Data::withStringKeys($input);
                    } elseif ($n > 0 && $seed === 3) {
                        // Non-list input: PHP's sort() reindexes, and so must we.
                        $input = Data::withStringKeys($input);
                    }
                    $expected = serialize(Data::native($input, $order, $flags, $preserve));
                    $copy = $input;
                    $this->assertSame($expected, serialize($sorter->sort($input)), "$name n=$n seed=$seed");
                    $this->assertSame($expected, serialize($keyed->sort($input)), "$name n=$n seed=$seed (key function)");
                    $this->assertSame($copy, $input, 'the input is not modified');
                    ++$cases;
                }
            }
        }
        $this->assertGreaterThan(400, $cases);
    }

    /** ksort()/krsort() with every flag, on PHP 8.2+ semantics; the key sets have a consistent order. */
    #[DataProvider('configurations')]
    public function testSortKeysEqualsKsort(Comparison $comparison, Order $order, bool $unused): void
    {
        $sorter = Sorter::create($comparison, $order);
        $flags = Data::flags($comparison);
        $keySets = [
            'int keys' => fn() => mt_rand(-50, 50),
            'numeric string keys' => fn() => mt_rand(0, 1) ? (string) (mt_rand(-40, 40) / 4) : sprintf('%.2f', mt_rand(-40, 40) / 4),
            'letter keys' => fn() => Data::word('abc', 1, 4),
            'letter keys, both cases (equal when case is ignored)' => fn() => Data::word('abAB', 1, 4),
            // PHP 8.2 changed ksort() on mixed keys to the standard comparison (GH-9296); under 8.0/8.1 it
            // converted strings to numbers. All digits sort before all letters, so the order is consistent.
            'mixed int and letter keys' => fn() => mt_rand(0, 1) ? mt_rand(-30, 30) : Data::word('abc', 1, 3),
            'numeric and letter keys' => fn() => mt_rand(0, 2) === 0 ? sprintf('%.1f', mt_rand(-30, 30) / 2) : (mt_rand(0, 1) ? mt_rand(-30, 30) : Data::word('abc', 1, 3)),
        ];
        foreach ($keySets as $name => $makeKey) {
            foreach ([0, 1, 2, 16, 17, 18, 40, 300] as $n) {
                for ($seed = 1; $seed <= 5; ++$seed) {
                    mt_srand($seed * 7 + $n);
                    $input = [];
                    for ($i = 0; $i < $n; ++$i) {
                        $input[$makeKey()] = $i;
                    }
                    $expected = $input;
                    $order === Order::Ascending ? ksort($expected, $flags) : krsort($expected, $flags);
                    $this->assertSame(serialize($expected), serialize($sorter->sortKeys($input)), "$name n=$n seed=$seed");
                }
            }
        }
    }

    public function testSizeBoundaries(): void
    {
        $patterns = [
            'sorted' => fn(int $n) => range(0, $n - 1),
            'reversed' => fn(int $n) => range($n - 1, 0),
            'all equal' => fn(int $n) => array_fill(0, $n, 5),
            'random' => fn(int $n) => array_map(fn() => mt_rand(0, 20), range(1, $n)),
            'random, distinct' => function (int $n) {
                $a = range(0, $n - 1);
                shuffle($a);
                return $a;
            },
        ];
        $asc = Sorter::create();
        $desc = Sorter::create(order: Order::Descending);
        foreach ([0, 1, 2, 15, 16, 17, 18, 33] as $n) {
            foreach ($patterns as $name => $make) {
                for ($seed = 0; $seed < 20; ++$seed) {
                    mt_srand($seed);
                    $input = $make($n);
                    $a = $input;
                    sort($a);
                    $d = $input;
                    rsort($d);
                    $this->assertSame($a, $asc->sort($input), "$name n=$n ascending");
                    $this->assertSame($d, $desc->sort($input), "$name n=$n descending");
                }
            }
        }
    }

    /** Equal elements keep their order when only part of the value is the key. */
    public function testStabilityWithKeyFunction(): void
    {
        foreach ([Order::Ascending, Order::Descending] as $order) {
            foreach ([5, 17, 18, 100, 3000] as $n) {
                mt_srand($n);
                $rows = [];
                for ($i = 0; $i < $n; ++$i) {
                    $rows[] = ['group' => mt_rand(0, 5), 'id' => $i];
                }
                $sorted = (Sorter::by(fn(array $r) => $r['group'], order: $order))->sort($rows);
                $expected = $rows;
                usort($expected, fn($x, $y) => $order === Order::Ascending ? $x['group'] <=> $y['group'] : $y['group'] <=> $x['group']);
                $this->assertSame($expected, $sorted, "n=$n {$order->name}");
            }
        }
    }

    public function testNumericStringsInSeveralNotationsKeepTheirOrder(): void
    {
        $input = array_merge(array_fill(0, 40, '7'), ['7.0', '07', '7e0', ' 7', '7 ', '7.00']);
        shuffle($input);
        $this->assertSame(serialize(Data::native($input, Order::Ascending, SORT_REGULAR)), serialize((Sorter::create())->sort($input)));
        $this->assertSame(serialize(Data::native($input, Order::Descending, SORT_REGULAR)), serialize((Sorter::create(order: Order::Descending))->sort($input)));
    }
}
