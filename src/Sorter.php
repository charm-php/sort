<?php

declare(strict_types=1);

namespace Charm\Sort;

/**
 * A stable sort configured once and used many times.
 *
 * A Sorter is made with one of four static constructors, each taking its own kind of argument:
 *
 * ```php
 * // A comparison function like usort()'s, but stable and faster:
 * Sorter::using(fn($a, $b) => $a['age'] <=> $b['age'])->sort($people);
 *
 * // Sort by a derived value, computed once per element (faster than a comparison function):
 * Sorter::by(fn(User $u) => $u->age)->sort($users);
 *
 * // One of PHP's own comparisons:
 * Sorter::create(Comparison::Natural, Order::Descending)->sort($filenames);
 *
 * // Locale-aware, with ext-intl:
 * Sorter::collated(new \Collator('nb_NO'))->sort($names);
 * ```
 *
 * The result is always a new array and the input is never modified. Elements that compare equal
 * keep their original order, in both directions. With a built-in {@see Comparison} the result is
 * identical to PHP's `sort()`; see the README for the mapping to `rsort()`, `asort()`, `ksort()`,
 * `usort()` and the `SORT_*` flags.
 *
 * Inputs without a consistent order (NaN, or a mix of numeric and non-numeric strings under
 * `Comparison::Regular`) have no single correct sorted order; the result is then produced by
 * this class's own algorithm and may differ from PHP's.
 */
final readonly class Sorter
{
    /** The Loops method that sorts with this configuration. */
    private string $loop;

    /** The prefix of the Loops methods that sort array keys. */
    private string $keysLoop;

    private function __construct(
        private Comparison $comparison,
        private ?\Closure $by,
        private ?\Closure $comparator,
        public Order $order,
        public bool $preserveKeys,
    ) {
        $ascending = $order === Order::Ascending;
        $this->keysLoop = $ascending ? 'keysAsc' : 'keysDesc';
        $this->loop = $comparator !== null
            ? ($ascending ? 'mergeAsc' : 'mergeDesc')
            : ($ascending ? 'asc' : 'desc') . match ($comparison) {
                Comparison::Regular, Comparison::Numeric => 'Regular',
                Comparison::Binary => 'Binary',
                Comparison::BinaryCaseInsensitive => 'BinaryCaseInsensitive',
                Comparison::Natural => 'Natural',
                Comparison::NaturalCaseInsensitive => 'NaturalCaseInsensitive',
            };
    }

    /**
     * A sorter that compares the values with one of PHP's built-in comparisons.
     *
     * With the defaults, `sort()` is `sort()`, with `Order::Descending` it is `rsort()`, with
     * `preserveKeys` it is `asort()` / `arsort()`, and `sortKeys()` is `ksort()` / `krsort()`.
     *
     * @param Comparison $comparison How values are compared; each case is a flag of PHP's `sort()`.
     * @param Order $order Ascending or descending.
     * @param bool $preserveKeys Keep the array keys, like `asort()`, instead of returning a list.
     */
    public static function create(
        Comparison $comparison = Comparison::Regular,
        Order $order = Order::Ascending,
        bool $preserveKeys = false,
    ): self {
        return new self($comparison, null, null, $order, $preserveKeys);
    }

    /**
     * A sorter that sorts by a value derived from each element, for example a property or a
     * normalised string.
     *
     * `$by` is called exactly once per element, before sorting, so it is much cheaper than doing the
     * same work inside a comparison function. The derived values are compared with `$comparison`.
     * In `sortKeys()` it is called with each array key instead.
     *
     * A derived value that is an array makes the sort compare column by column (lexicographically,
     * the shorter array first when it is a prefix of the other), not with PHP's `<` on arrays, which
     * compares the number of elements first. That is how to sort by several columns:
     * `Sorter::by(fn($r) => [$r['last'], $r['first']])`.
     *
     * @param \Closure $by `fn($element): mixed`
     * @param Comparison $comparison How the derived values are compared. The default is the
     *        standard comparison, so derived numbers are compared as numbers.
     */
    public static function by(
        \Closure $by,
        Comparison $comparison = Comparison::Regular,
        Order $order = Order::Ascending,
        bool $preserveKeys = false,
    ): self {
        return new self($comparison, $by, null, $order, $preserveKeys);
    }

    /**
     * A sorter with a comparison function like the one `usort()` takes: `fn($a, $b): int`, returning
     * a negative, zero or positive number. `sort()` corresponds to `usort()`, with `preserveKeys` to
     * `uasort()`, and `sortKeys()` to `uksort()` (the function is then called with the array keys).
     *
     * The sort is stable. `Order::Descending` negates the function's result (it does not reverse the
     * ascending result), so equal elements keep their original order. A bool result is an error
     * (PHP 8 only deprecates it); return `$a <=> $b`. As `usort()` does, a float result is truncated.
     *
     * @param \Closure $comparator `fn($a, $b): int`
     */
    public static function using(
        \Closure $comparator,
        Order $order = Order::Ascending,
        bool $preserveKeys = false,
    ): self {
        return new self(Comparison::Regular, null, $comparator, $order, $preserveKeys);
    }

    /**
     * A sorter for locale-aware sorting with an `\Collator` (requires ext-intl).
     *
     * One ICU sort key is computed per element, and the keys are compared as bytes. That is much
     * faster than `Sorter::using($collator->compare(...))`, which gives the same order but calls the
     * collator for every comparison. Values are converted to strings first.
     *
     * ```php
     * Sorter::collated(new \Collator('nb_NO'))->sort(['Åse', 'Zorro', 'Ole', 'Ærlig']);
     * // ['Ole', 'Zorro', 'Ærlig', 'Åse']
     * ```
     *
     * @throws \ValueError (from sorting) if the collator cannot make a sort key for an element,
     *         which happens for strings that are not valid UTF-8.
     */
    public static function collated(
        \Collator $collator,
        Order $order = Order::Ascending,
        bool $preserveKeys = false,
    ): self {
        return self::by(static function (mixed $element) use ($collator): string {
            $string = (string) $element;
            $sortKey = $collator->getSortKey($string);
            if ($sortKey === false) {
                throw new \ValueError('Collator::getSortKey() failed for the element ' . json_encode($string, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) . ': is it valid UTF-8?');
            }
            return $sortKey;
        }, Comparison::Binary, $order, $preserveKeys);
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
        if ($this->by === null) {
            if ($this->comparator !== null) {
                $sorted = \array_values($values);
                $offsets = Loops::{$this->loop}($sorted, $this->comparator);
                return $this->preserveKeys ? self::arrange(\array_values($values), \array_keys($values), $offsets) : $sorted;
            }
            // The common case: nothing derived, no keys to carry along. The loop sorts the list directly.
            if ($this->comparison === Comparison::Regular && !$this->preserveKeys) {
                $a = \array_is_list($values) ? $values : \array_values($values);
                Loops::{$this->loop}($a, false);
                return $a;
            }
        }

        $keys = $this->preserveKeys ? \array_keys($values) : null;
        $values = \array_values($values);
        if ($this->by === null) {
            $derived = $values;
        } else {
            $by = $this->by;
            $derived = [];
            foreach ($values as $value) {
                $derived[] = $by($value);
            }
        }
        return self::arrange($values, $keys, $this->offsets($derived));
    }

    /**
     * Sorts by array key, like `ksort()` / `krsort()`, or `uksort()` with `using()`; the values follow
     * their keys. Returns a new array. With `by()`, the derived value is computed from each key.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public function sortKeys(array $values): array
    {
        $keys = \array_keys($values);
        if ($this->by !== null) {
            $by = $this->by;
            $derived = [];
            foreach ($keys as $key) {
                $derived[] = $by($key);
            }
            return self::arrange(\array_values($values), $keys, $this->offsets($derived));
        }

        // Array keys are unique. Where distinct keys can never compare as equal, stability can't be
        // observed and a plain quicksort on the keys is enough; with a comparison function the merge sort
        // sorts the keys. Either way the values are then fetched by key.
        if ($this->comparator !== null) {
            Loops::{$this->loop}($keys, $this->comparator);
        } else {
            $loop = $this->keysLoop($keys);
            if ($loop === null) {
                return self::arrange(\array_values($values), $keys, $this->offsets($keys));
            }
            Loops::{$loop}($keys);
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $values[$key];
        }
        return $result;
    }

    /**
     * The key loop that gives the same order as the general path, or null when distinct keys could
     * compare as equal ("1.0" and 1 under Regular, "a" and "A" case-insensitively), where the general,
     * stable path is needed. May convert $keys to strings.
     *
     * @param list<int|string> $keys
     */
    private function keysLoop(array &$keys): ?string
    {
        if ($this->comparison === Comparison::Binary) {
            // The string forms of distinct keys are distinct: an int key is its canonical decimal form
            // and a string key is never a canonical integer. A string "5" used as an array key later is key 5 again.
            foreach ($keys as $i => $key) {
                $keys[$i] = (string) $key;
            }
            return $this->keysLoop . 'String';
        }
        if ($this->comparison !== Comparison::Regular) {
            return null;
        }
        $ints = true;
        $strings = true;
        foreach ($keys as $key) {
            if (\is_int($key)) {
                $strings = false;
            } elseif (\is_numeric($key)) {
                // Numeric strings compare as numbers, and "1.0" equals "1".
                return null;
            } else {
                $ints = false;
            }
        }
        // Non-numeric strings compare bytewise under Regular.
        return $ints ? $this->keysLoop . 'Int' : ($strings ? $this->keysLoop . 'String' : null);
    }

    /**
     * The original offsets of $derived in sorted order.
     *
     * @param list<mixed> $derived the values to compare
     * @return list<int>
     */
    private function offsets(array $derived): array
    {
        if ($this->comparator !== null) {
            return Loops::{$this->loop}($derived, $this->comparator);
        }
        $n = \count($derived);
        if ($n < 2) {
            return $n === 1 ? [0] : [];
        }

        // Passes, most significant first. Array values are columns, one pass each.
        $passes = $this->by !== null && self::hasArray($derived) ? $this->columnPasses($derived) : [$derived];

        // Several columns are a series of stable sorts, least significant column first.
        $offsets = \range(0, $n - 1);
        for ($p = \count($passes) - 1; $p >= 0; --$p) {
            $column = $passes[$p];
            if ($p < \count($passes) - 1) {
                $ordered = [];
                foreach ($offsets as $i) {
                    $ordered[] = $column[$i];
                }
                $column = $ordered;
            }
            // Convert once, so the loop doesn't have to; the sorted original values are found through the offsets.
            if ($this->comparison === Comparison::Numeric) {
                foreach ($column as $i => $value) {
                    $column[$i] = (float) $value;
                }
            } elseif ($this->comparison !== Comparison::Regular) {
                foreach ($column as $i => $value) {
                    $column[$i] = (string) $value;
                }
            }
            $sorted = Loops::{$this->loop}($column, true);
            $composed = [];
            foreach ($sorted as $i) {
                $composed[] = $offsets[$i];
            }
            $offsets = $composed;
        }
        return $offsets;
    }

    /**
     * Turns array values into one column per position. A value that is too short to have a column
     * sorts before every value that has it, which takes an extra pass on whether the column exists.
     *
     * @param list<mixed> $derived
     * @return list<list<mixed>>
     */
    private function columnPasses(array $derived): array
    {
        $rows = [];
        $width = 0;
        foreach ($derived as $value) {
            $row = \array_values((array) $value);
            $rows[] = $row;
            $width = \max($width, \count($row));
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
                $passes[] = $exists;
            }
            $passes[] = $column;
        }
        return $passes;
    }

    /** @param list<mixed> $derived */
    private static function hasArray(array $derived): bool
    {
        foreach ($derived as $value) {
            if (\is_array($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<mixed> $values
     * @param list<int|string>|null $keys
     * @param list<int> $offsets
     * @return array<mixed>
     */
    private static function arrange(array $values, ?array $keys, array $offsets): array
    {
        $result = [];
        if ($keys === null) {
            foreach ($offsets as $i) {
                $result[] = $values[$i];
            }
        } else {
            foreach ($offsets as $i) {
                $result[$keys[$i]] = $values[$i];
            }
        }
        return $result;
    }
}
