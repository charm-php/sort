<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
use Charm\Sort\Order;

/**
 * Seeded input generators and the mapping from Comparison to PHP's sort flags.
 *
 * Every data set has a consistent order under the comparisons it is used with: no NaN, and no
 * mix of numeric and non-numeric strings under Regular, where PHP's own result depends on the
 * algorithm (see the README).
 */
final class Data
{
    /** Sizes around the insertion sort cutoffs (16 and 17) and the final-pass sentinel window. */
    public const SIZES = [0, 1, 2, 3, 5, 15, 16, 17, 18, 33, 34, 100, 257, 1000];

    public static function flags(Comparison $c): int
    {
        return match ($c) {
            Comparison::Regular => SORT_REGULAR,
            Comparison::Binary => SORT_STRING,
            Comparison::BinaryCaseInsensitive => SORT_STRING | SORT_FLAG_CASE,
            Comparison::Numeric => SORT_NUMERIC,
            Comparison::Natural => SORT_NATURAL,
            Comparison::NaturalCaseInsensitive => SORT_NATURAL | SORT_FLAG_CASE,
        };
    }

    /** Native equivalent of Sorter->sort() with default flags: sort() or rsort() on a copy. */
    public static function native(array $a, Order $order, int $flags, bool $preserveKeys = false): array
    {
        if ($preserveKeys) {
            $order === Order::Ascending ? asort($a, $flags) : arsort($a, $flags);
        } else {
            $order === Order::Ascending ? sort($a, $flags) : rsort($a, $flags);
        }
        return $a;
    }

    /**
     * Data sets that have a consistent order under every Comparison.
     *
     * @return array<string, callable(int): array>
     */
    public static function sets(): array
    {
        return [
            'ints, many duplicates' => fn(int $n) => self::fill($n, fn() => mt_rand(0, intdiv($n, 2) + 1)),
            'ints, full range' => fn(int $n) => self::fill($n, fn() => mt_rand(-PHP_INT_MAX, PHP_INT_MAX)),
            'floats' => fn(int $n) => self::fill($n, fn() => mt_rand(-500, 500) / 8),
            // 0.0 and -0.0 are equal but distinguishable; so are "7", "7.0", "07", "7e0" and " 7".
            'floats with -0.0' => fn(int $n) => self::fill($n, fn() => mt_rand(0, 3) === 0 ? [0.0, -0.0][mt_rand(0, 1)] : (float) mt_rand(-3, 3)),
            'numeric strings, equal values in several notations' => fn(int $n) => self::fill($n, function () {
                $v = mt_rand(1, 40);
                return [(string) $v, "$v.0", "0$v", "{$v}e0"][mt_rand(0, 3)];
            }),
            'letters, both cases' => fn(int $n) => self::fill($n, fn() => self::word('abcABC', 1, 4)),
            'names with digits' => fn(int $n) => self::fill($n, fn() => self::word('ab', 1, 2) . mt_rand(1, 30) . self::word('xX', 0, 1)),
            'long common prefix' => fn(int $n) => self::fill($n, fn() => 'common-prefix-' . self::word('ab', 0, 3)),
            'already sorted' => fn(int $n) => self::fill($n, fn() => 0, true),
            'reversed' => fn(int $n) => array_reverse(self::fill($n, fn() => 0, true)),
            'all equal' => fn(int $n) => array_fill(0, $n, 7),
            'sorted with a late element out of order' => function (int $n) {
                $a = self::fill($n, fn() => 0, true);
                if ($n > 3) {
                    $a[$n - 2] = -1;
                }
                return $a;
            },
        ];
    }

    /** Distinct string keys in random order, for non-list inputs. */
    public static function withStringKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $i => $_) {
            $keys[] = "k$i";
        }
        shuffle($keys);
        return array_combine($keys, $values);
    }

    public static function word(string $alphabet, int $min, int $max): string
    {
        $s = '';
        for ($i = mt_rand($min, $max); $i > 0; --$i) {
            $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        return $s;
    }

    /** @param callable(): mixed $f */
    private static function fill(int $n, callable $f, bool $counting = false): array
    {
        $a = [];
        for ($i = 0; $i < $n; ++$i) {
            $a[] = $counting ? $i : $f();
        }
        return $a;
    }
}
