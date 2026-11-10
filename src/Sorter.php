<?php

declare(strict_types=1);

namespace Charm\Sort;

/**
 * A stable sort configured once and used many times.
 *
 * ```php
 * $sorter = new Sorter(Order::Descending);
 * $sorter->sort([3, 1, 2]);                              // [3, 2, 1]
 * (new Sorter(key: fn($p) => $p->age))->sort($people);
 * ```
 *
 * The result is always a new array and the input is never modified. Elements that compare equal
 * keep their original order, in both directions. With the default settings the result is
 * identical to PHP's `sort()`; see the README for the mapping to `rsort()`, `asort()`,
 * `ksort()` and the `SORT_*` flags.
 *
 * Inputs without a consistent order (NaN, or a mix of numeric and non-numeric strings under
 * `Comparison::Regular`) have no single correct sorted order; the result is then sorted by
 * this class's own algorithm and may differ from PHP's.
 */
final readonly class Sorter
{
    /** Name of the Loops method for this order and comparison. */
    private string $loop;

    /**
     * @param Order $order Ascending or descending.
     * @param Comparison $comparison How values, or sort keys when `$key` is given, are compared.
     * @param \Closure|null $key Derives the sort key from a value. Called exactly once per
     *        element, before sorting. The keys are compared with `$comparison`. When a key is an
     *        array, the keys are compared column by column (lexicographically, the shorter
     *        array first when it is a prefix of the other), not with PHP's `<` on arrays, which
     *        compares the number of elements first.
     * @param bool $preserveKeys Keep the array keys, like `asort()`, instead of returning a list.
     * @param array<string|int, Order>|null $columns Internal: set by {@see byColumns()}.
     * @param bool $versions Internal: set by {@see versions()}.
     */
    public function __construct(
        public Order $order = Order::Ascending,
        public Comparison $comparison = Comparison::Regular,
        public ?\Closure $key = null,
        public bool $preserveKeys = false,
        private ?array $columns = null,
        private bool $versions = false,
    ) {
        $this->loop = self::loopName($order, $comparison);
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
        // The common case: no key function, no keys to carry along. The loop sorts the list directly.
        if ($this->key === null && $this->comparison === Comparison::Regular && !$this->preserveKeys
            && $this->columns === null && !$this->versions) {
            $a = array_is_list($values) ? $values : array_values($values);
            Loops::{$this->loop}($a, false);
            return $a;
        }

        $keys = $this->preserveKeys ? array_keys($values) : null;
        $values = array_values($values);
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
     * Sorts by array key, like `ksort()` / `krsort()`; the values follow their keys. Returns a new
     * array. If a key function is set, it is called with the array key (not the value).
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public function sortKeys(array $values): array
    {
        $keys = array_keys($values);
        if ($this->key === null) {
            $sortKeys = $keys;
        } else {
            $key = $this->key;
            $sortKeys = [];
            foreach ($keys as $k) {
                $sortKeys[] = $key($k);
            }
        }
        return self::arrange(array_values($values), $keys, $this->permutation($sortKeys));
    }

    /**
     * A sorter for locale-aware sorting with an `\Collator` (ext-intl).
     *
     * The sort key is `$collator->getSortKey()` of the value (or of `$key($value)`), compared
     * bytewise. Using `Comparison::Binary` is the point: a collation key that happens to look
     * numeric must not be compared as a number.
     *
     * ```php
     * Sorter::collated(new \Collator('nb_NO'))->sort(['Åse', 'Zorro', 'Ole', 'Ærlig']);
     * // ['Ole', 'Zorro', 'Ærlig', 'Åse']
     * ```
     *
     * @param \Collator $collator Requires ext-intl, which is why it is only a suggestion.
     * @param \Closure|null $key Derives the string to collate from a value.
     */
    public static function collated(\Collator $collator, ?\Closure $key = null, Order $order = Order::Ascending): self
    {
        return new self(
            $order,
            Comparison::Binary,
            static fn(mixed $value): string => (string) $collator->getSortKey((string) ($key === null ? $value : $key($value))),
        );
    }

    /**
     * Sorts rows (arrays or objects) by several columns, each with its own direction, like
     * `array_multisort()`. The columns are listed most significant first.
     *
     * Array rows are read by offset, objects by property; a missing column counts as `null`. The
     * values of a column are compared with `Comparison::Regular`.
     *
     * ```php
     * Sorter::byColumns(['age' => Order::Descending, 'name' => Order::Ascending])->sort($people);
     * ```
     *
     * @param array<string|int, Order> $columns Column name (or offset) => direction.
     */
    public static function byColumns(array $columns): self
    {
        return new self(columns: $columns);
    }

    /**
     * Sorts version strings in the order of `version_compare()`: `1.9` before `1.10`, and
     * `1.0.0-beta` before `1.0.0`.
     */
    public static function versions(Order $order = Order::Ascending): self
    {
        return new self($order, versions: true);
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
     * @param list<mixed> $items sort keys, or the rows when sorting by columns
     * @return list<int>
     */
    private function permutation(array $items): array
    {
        $n = \count($items);
        if ($n < 2) {
            return $n === 1 ? [0] : [];
        }

        if ($this->versions) {
            $ix = range(0, $n - 1);
            $sign = $this->order === Order::Ascending ? 1 : -1;
            // usort() is stable.
            usort($ix, static fn(int $i, int $j): int => $sign * version_compare((string) $items[$i], (string) $items[$j]));
            return $ix;
        }

        // Passes, most significant first: [list of keys, direction].
        if ($this->columns !== null) {
            $passes = [];
            foreach ($this->columns as $name => $order) {
                $column = [];
                foreach ($items as $row) {
                    $column[] = \is_array($row) ? ($row[$name] ?? null) : ($row->$name ?? null);
                }
                $passes[] = [$column, $order];
            }
        } elseif ($this->key !== null && self::hasArray($items)) {
            $passes = $this->columnPasses($items);
        } else {
            $passes = [[$items, $this->order]];
        }

        // Several columns are a series of stable sorts, least significant column first.
        $perm = range(0, $n - 1);
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
            if ($this->comparison === Comparison::Numeric) {
                foreach ($keys as $i => $k) {
                    $keys[$i] = (float) $k;
                }
            } elseif ($this->comparison !== Comparison::Regular) {
                foreach ($keys as $i => $k) {
                    $keys[$i] = (string) $k;
                }
            }
            $loop = self::loopName($order, $this->comparison);
            $ix = Loops::{$loop}($keys, true);
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
            $row = array_values((array) $item);
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
