<?php

declare(strict_types=1);

namespace Charm\Sort;

/**
 * A stable sort configured once and used many times.
 *
 * ```php
 * $sorter = new Sorter(fn($a, $b) => $a['age'] <=> $b['age']);   // like usort(), but stable and faster
 * $sorter->sort($people);
 * (new Sorter(key: fn($p) => $p->age))->sort($people);            // faster still: one call per element
 * (new Sorter(order: Order::Descending))->sort([3, 1, 2]);        // [3, 2, 1]
 * ```
 *
 * The result is always a new array and the input is never modified. Elements that compare equal
 * keep their original order, in both directions. With the built-in comparisons the result is
 * identical to PHP's `sort()`; see the README for the mapping to `rsort()`, `asort()`,
 * `ksort()`, `usort()` and the `SORT_*` flags.
 *
 * Inputs without a consistent order (NaN, or a mix of numeric and non-numeric strings under
 * `Comparison::Regular`) have no single correct sorted order; the result is then sorted by
 * this class's own algorithm and may differ from PHP's.
 */
final readonly class Sorter
{
    /** Name of the Loops method that does the sorting. */
    private string $loop;

    /**
     * @param Comparison|\Closure $compare A built-in {@see Comparison}, or a function `fn($a, $b): int` like the
     *        one `usort()` takes (negative, zero or positive). The function is called with the values, or with
     *        the derived keys when `$key` is given. A bool return value is an error; return `$a <=> $b`.
     *        With a closure, `Order::Descending` negates the result, and equal elements still keep their
     *        original order.
     * @param Order $order Ascending or descending.
     * @param \Closure|null $key Derives the value to compare from each element. Called exactly once per
     *        element, before sorting, so it is much cheaper than doing the same work inside a comparison
     *        function. With a built-in comparison, a key that is an array makes the keys compare column by column
     *        (lexicographically, the shorter array first when it is a prefix of the other), not with PHP's `<` on
     *        arrays, which compares the number of elements first.
     * @param bool $preserveKeys Keep the array keys, like `asort()`, instead of returning a list.
     */
    public function __construct(
        public Comparison|\Closure $compare = Comparison::Regular,
        public Order $order = Order::Ascending,
        public ?\Closure $key = null,
        public bool $preserveKeys = false,
    ) {
        $this->loop = $compare instanceof \Closure
            ? ($order === Order::Ascending ? 'mergeAsc' : 'mergeDesc')
            : self::loopName($order, $compare);
    }

    /**
     * Sorts the values. Returns a new array: a list, or with `preserveKeys` the values under their
     * original keys.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public function sort(array $values): array
    {
        if ($this->key === null) {
            if ($this->compare instanceof \Closure) {
                $sorted = \array_values($values);
                $ix = Loops::{$this->loop}($sorted, $this->compare);
                return $this->preserveKeys ? self::arrange(\array_values($values), \array_keys($values), $ix) : $sorted;
            }
            // The common case: no key function, no keys to carry along. The loop sorts the list directly.
            if ($this->compare === Comparison::Regular && !$this->preserveKeys) {
                $a = \array_is_list($values) ? $values : \array_values($values);
                Loops::{$this->loop}($a, false);
                return $a;
            }
        }

        $keys = $this->preserveKeys ? \array_keys($values) : null;
        $values = \array_values($values);
        if ($this->key === null) {
            $sortKeys = $values;
        } else {
            $key = $this->key;
            $sortKeys = [];
            foreach ($values as $value) {
                $sortKeys[] = $key($value);
            }
        }
        return self::arrange($values, $keys, $this->permutation($sortKeys));
    }

    /**
     * Sorts by array key, like `ksort()` / `krsort()` (or `uksort()` with a comparison function); the
     * values follow their keys. Returns a new array. A key function or comparison function is called with the
     * array keys, not the values.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public function sortKeys(array $values): array
    {
        $keys = \array_keys($values);
        if ($this->key === null) {
            // Array keys are unique. Where distinct keys can never compare as equal, stability can't be
            // observed and a plain quicksort on the keys is enough; with a comparison function the merge sort
            // sorts the keys. Either way the values are then fetched by key.
            if ($this->compare instanceof \Closure) {
                Loops::{$this->loop}($keys, $this->compare);
            } else {
                $loop = $this->keyLoop($keys);
                if ($loop === null) {
                    return self::arrange(\array_values($values), $keys, $this->permutation($keys));
                }
                Loops::{$loop}($keys);
            }
            $result = [];
            foreach ($keys as $k) {
                $result[$k] = $values[$k];
            }
            return $result;
        }
        $key = $this->key;
        $sortKeys = [];
        foreach ($keys as $k) {
            $sortKeys[] = $key($k);
        }
        return self::arrange(\array_values($values), $keys, $this->permutation($sortKeys));
    }

    /**
     * The unstable key loop that gives the same order as the general path, or null when distinct
     * keys could compare as equal ("1.0" and 1 under Regular, "a" and "A" case-insensitively), where
     * the general, stable path is needed. May convert $keys to strings.
     *
     * @param list<int|string> $keys
     */
    private function keyLoop(array &$keys): ?string
    {
        $dir = $this->order === Order::Ascending ? 'keysAsc' : 'keysDesc';
        if ($this->compare === Comparison::Binary) {
            // The string forms of distinct keys are distinct: an int key is its canonical decimal form
            // and a string key is never a canonical integer. A string "5" used as an array key later is key 5 again.
            foreach ($keys as $i => $k) {
                $keys[$i] = (string) $k;
            }
            return $dir . 'String';
        }
        if ($this->compare !== Comparison::Regular) {
            return null;
        }
        $ints = true;
        $plainStrings = true;
        foreach ($keys as $k) {
            if (\is_int($k)) {
                $plainStrings = false;
            } elseif (\is_numeric($k)) {
                // Numeric strings compare as numbers, and "1.0" equals "1".
                return null;
            } else {
                $ints = false;
            }
        }
        // Non-numeric strings compare bytewise under Regular.
        return $ints ? $dir . 'Int' : ($plainStrings ? $dir . 'String' : null);
    }

    private static function loopName(Order $order, Comparison $comparison): string
    {
        return ($order === Order::Ascending ? 'asc' : 'desc') . match ($comparison) {
            Comparison::Regular, Comparison::Numeric => 'Regular',
            Comparison::Binary => 'Binary',
            Comparison::BinaryCaseInsensitive => 'BinaryCaseInsensitive',
            Comparison::Natural => 'Natural',
            Comparison::NaturalCaseInsensitive => 'NaturalCaseInsensitive',
        };
    }

    /**
     * The offsets of $items in sorted order.
     *
     * @param list<mixed> $items the sort keys
     * @return list<int>
     */
    private function permutation(array $items): array
    {
        if ($this->compare instanceof \Closure) {
            return Loops::{$this->loop}($items, $this->compare);
        }
        $n = \count($items);
        if ($n < 2) {
            return $n === 1 ? [0] : [];
        }

        // Passes, most significant first: [list of keys, direction].
        $passes = $this->key !== null && self::hasArray($items) ? $this->columnPasses($items) : [[$items, $this->order]];

        // Several columns are a series of stable sorts, least significant column first.
        $perm = \range(0, $n - 1);
        for ($p = \count($passes) - 1; $p >= 0; --$p) {
            [$keys, $order] = $passes[$p];
            if ($p < \count($passes) - 1) {
                $ordered = [];
                foreach ($perm as $i) {
                    $ordered[] = $keys[$i];
                }
                $keys = $ordered;
            }
            // Convert once, so the loop doesn't have to; the sorted original values are found through the offsets.
            if ($this->compare === Comparison::Numeric) {
                foreach ($keys as $i => $k) {
                    $keys[$i] = (float) $k;
                }
            } elseif ($this->compare !== Comparison::Regular) {
                foreach ($keys as $i => $k) {
                    $keys[$i] = (string) $k;
                }
            }
            $ix = Loops::{self::loopName($order, $this->compare)}($keys, true);
            $composed = [];
            foreach ($ix as $i) {
                $composed[] = $perm[$i];
            }
            $perm = $composed;
        }
        return $perm;
    }

    /**
     * Turns array keys into one pass per column. A key that is too short to have a column sorts
     * before every key that has it, which takes an extra pass on whether the column exists.
     *
     * @param list<mixed> $items
     * @return list<array{0: list<mixed>, 1: Order}>
     */
    private function columnPasses(array $items): array
    {
        $rows = [];
        $width = 0;
        foreach ($items as $item) {
            $row = \array_values((array) $item);
            $rows[] = $row;
            $width = max($width, \count($row));
        }
        $passes = [];
        for ($c = 0; $c < $width; ++$c) {
            $exists = [];
            $column = [];
            foreach ($rows as $row) {
                $has = \array_key_exists($c, $row);
                $exists[] = (int) $has;
                $column[] = $has ? $row[$c] : null;
            }
            if (\in_array(0, $exists, true)) {
                $passes[] = [$exists, $this->order];
            }
            $passes[] = [$column, $this->order];
        }
        return $passes;
    }

    private static function hasArray(array $items): bool
    {
        foreach ($items as $item) {
            if (\is_array($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<mixed> $values
     * @param list<int|string>|null $keys
     * @param list<int> $perm
     */
    private static function arrange(array $values, ?array $keys, array $perm): array
    {
        $result = [];
        if ($keys === null) {
            foreach ($perm as $i) {
                $result[] = $values[$i];
            }
        } else {
            foreach ($perm as $i) {
                $result[$keys[$i]] = $values[$i];
            }
        }
        return $result;
    }
}
