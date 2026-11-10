# charm/sort

Stable sorting for PHP, with the same result as PHP's own sort functions, written in PHP.

```php
use Charm\Sort\Sorter;

$sorted = Sorter::using(fn($a, $b) => $a['age'] <=> $b['age'])->sort($people);
```

- **Stable.** Elements that compare equal keep their original order, in both directions. PHP's `sort()` is stable since 8.0; this keeps the guarantee for `rsort()`-style descending sorts and for comparison functions too.
- **Returns a new array** and never touches its input.
- **Same output as PHP.** With the built-in comparisons the result is identical to `sort()`, `rsort()`, `asort()`, `arsort()`, `ksort()` and `krsort()` with the `SORT_*` flags, and a comparison function behaves like `usort()`, `uasort()` and `uksort()`. The test suite proves it with `serialize()` on tens of thousands of generated inputs.
- **With the JIT, faster than `usort()`**, and faster than C `sort()` on large arrays of ints (see [Performance](#performance), including the cases where it is slower).

Requires PHP 8.2 or later. No dependencies. ext-intl is only needed for `Sorter::collated()`.

## Installation

```
composer require charm/sort
```

## Usage

A `Sorter` is made once with one of four static constructors and used as often as you like. Each constructor takes one kind of argument.

### `using()`: a comparison function, like `usort()`

```php
use Charm\Sort\Order;
use Charm\Sort\Sorter;

// By one field
$byAge = Sorter::using(fn($a, $b) => $a['age'] <=> $b['age']);
$people = $byAge->sort($people);

// Several columns: compare arrays, which PHP compares element by element
$byName = Sorter::using(fn($a, $b) => [$a['last'], $a['first']] <=> [$b['last'], $b['first']]);

// A descending column: swap $a and $b for that column
$mixed = Sorter::using(fn($a, $b) => [$b['age'], $a['last']] <=> [$a['age'], $b['last']]);

// Any function that returns an int
$versions = Sorter::using(version_compare(...))->sort(['1.10', '1.9', '1.0.0', '1.0.0-beta']);
// ['1.0.0-beta', '1.0.0', '1.9', '1.10']

// Descending: the comparison function's result is negated, equal elements keep their order
$newestFirst = Sorter::using(fn($a, $b) => $a['created'] <=> $b['created'], Order::Descending);
```

`sort()` corresponds to `usort()`, `Sorter::using($f, preserveKeys: true)` to `uasort()`, and `sortKeys()` to `uksort()` (the function then receives the array keys).

The function must return an int: `$a <=> $b`, `strcmp()`, `version_compare()`. A bool (`fn($a, $b) => $a > $b`) throws a `TypeError`: PHP 8 only deprecates it, and it cannot tell "equal" from "less". As in `usort()`, a float result is truncated to an int.

### `by()`: sort by a derived value

```php
$youngestFirst = Sorter::by(fn(User $u) => $u->age)->sort($users);

// Case-insensitive, with the derived string compared as bytes
$names = Sorter::by(strtolower(...), Comparison::Binary)->sort($names);

// Several columns, all ascending: derive an array; arrays are compared column by column
$byName = Sorter::by(fn($r) => [$r['last'], $r['first']])->sort($people);
```

The function is called exactly once per element, before sorting, so work such as `strtolower()` is done n times instead of about n log n times inside a comparison function. That is the reason `by()` is the fastest way to sort by anything computed. The derived values are compared with the `Comparison` you give (default `Regular`, so derived numbers compare as numbers).

A derived array compares column by column, like a list of sort columns. This is not PHP's `<` on arrays, which compares the number of elements first; here `[9]` sorts after `[1, 2]` and a prefix sorts first (`[1]` before `[1, 2]`).

In `sortKeys()`, the function is called with each array key.

### `create()`: PHP's built-in comparisons

```php
use Charm\Sort\Comparison;
use Charm\Sort\Order;

Sorter::create()->sort($values);                                   // sort()
Sorter::create(order: Order::Descending)->sort($values);           // rsort()
Sorter::create(preserveKeys: true)->sort($values);                 // asort()
Sorter::create(Comparison::Natural, Order::Descending)->sort($filenames);
Sorter::create()->sortKeys($values);                               // ksort()
```

| `Comparison` | PHP flag |
|---|---|
| `Regular` | `SORT_REGULAR` |
| `Binary` | `SORT_STRING` |
| `BinaryCaseInsensitive` | `SORT_STRING \| SORT_FLAG_CASE` |
| `Numeric` | `SORT_NUMERIC` |
| `Natural` | `SORT_NATURAL` |
| `NaturalCaseInsensitive` | `SORT_NATURAL \| SORT_FLAG_CASE` |

`SORT_LOCALE_STRING` is left out on purpose: it depends on the process-wide `setlocale()`. Use `collated()`.

| Call | Equals |
|---|---|
| `Sorter::create()->sort()` | `sort()` |
| `Sorter::create(order: Order::Descending)->sort()` | `rsort()` |
| `Sorter::create(preserveKeys: true)->sort()` | `asort()` |
| `Sorter::create(order: Order::Descending, preserveKeys: true)->sort()` | `arsort()` |
| `Sorter::create()->sortKeys()` | `ksort()` |
| `Sorter::create(order: Order::Descending)->sortKeys()` | `krsort()` |
| `Sorter::using($f)->sort()` / with `preserveKeys: true` / `->sortKeys()` | `usort()` / `uasort()` / `uksort()` |

### `collated()`: locale-aware sorting (ext-intl)

```php
use Charm\Sort\Sorter;

$names = ['Åse', 'Zorro', 'Ole', 'Ærlig', 'Øystein', 'Anne'];
Sorter::collated(new \Collator('nb_NO'))->sort($names);
// ['Anne', 'Ole', 'Zorro', 'Ærlig', 'Øystein', 'Åse']   Æ, Ø and Å come after Z in Norwegian

Sorter::collated(new \Collator('de_DE'), Order::Descending)->sort($words);
```

One ICU sort key is computed per element and the keys are compared as bytes. The same order, slower, is `Sorter::using((new \Collator('nb_NO'))->compare(...))`, which calls the collator for every comparison. Values are converted to strings; a string that is not valid UTF-8 throws a `ValueError` naming the element. `Sorter::collated($c)` is `Sorter::by($c->getSortKey(...), Comparison::Binary)` with those checks.

### Sorting by array key

`sortKeys()` sorts by array key and keeps the values with their keys: `ksort()` / `krsort()`, `uksort()` with `using()`, and with `by()` the derived value is computed from each key.

```php
Sorter::create()->sortKeys(['b' => 1, 'a' => 2, 'c' => 3]);   // ['a' => 2, 'b' => 1, 'c' => 3]
Sorter::using(fn($a, $b) => strlen($a) <=> strlen($b))->sortKeys($byName);
```

### Preserving keys

By default `sort()` returns a list, like `sort()`. With `preserveKeys: true` the values keep their keys, like `asort()`. Every constructor takes the argument.

### Properties

`$sorter->order` and `$sorter->preserveKeys` are public and read-only.

## Semantics

- **Stable, in both directions.** Descending sorts keep equal elements in their original order (as PHP's `rsort()` does); they are not the reversed ascending result. With `using()`, descending negates the comparison function's result.
- **A new array** is returned, the input is not modified, and the result of `sort()` is a list unless `preserveKeys` is set.
- **Identical to PHP's functions** for every `Comparison` and the equivalences listed above, compared with `serialize()`, so `0.0` and `-0.0`, or `"7"`, `"7.0"` and `"07"`, which are equal but distinguishable, must come out in the same order as PHP puts them.
- **Inputs without a consistent order.** When the comparison is not a consistent ordering, there is no single correct sorted order, and the result depends on the algorithm; PHP's own result is then an accident of `zend_sort`, and this library's may differ from it (it is still a permutation of the input). That means mixed numeric and non-numeric strings under `Comparison::Regular` (`"9"` < `"10"` < `"1a"` < `"9"` is a cycle), `NAN`, values of mixed types, and mixed int and string array keys such as `2`, `10`, `"1a"`. The tests leave these out of the identical-output comparison. Use `Comparison::Binary`, `Numeric` or `Natural` when the data is mixed, so that every value is converted first.
- **`ksort()` on mixed keys** compares int and string keys with the standard PHP 8 comparison since PHP 8.2 (GH-9296). This library requires PHP 8.2 and follows that behaviour; PHP 8.0 and 8.1 `ksort()` compared such keys differently.
- **Array keys that are unique** need no stability. `sortKeys()` skips the offset bookkeeping when distinct keys can never compare as equal (all int keys, all non-numeric string keys, `Comparison::Binary`), and uses the general stable path otherwise (for example `"1.0"` next to `1`, or case-insensitive comparisons).
- **Reentrant.** There is no shared state, so a comparison that runs user code (a `__toString()`, a comparison function) may use the same `Sorter` again.
- **Exceptions** thrown by a comparison function or a derived-value function propagate, and the `Sorter` stays usable.

## Performance

PHP 8.5.12, NTS, Linux x86-64. Every row below was checked: the output of `Sorter` is identical to `sort()`'s (`serialize()`), otherwise the benchmark fails. Times are the best of 7 runs after a warm-up, measured with `hrtime()`; the median agrees within a few percent. Table cells are speed relative to the native function: above 1.00x the PHP code is faster, below 1.00x it is slower.

Reproduce with:

```
composer install
composer bench          # = bench/run.sh 10,100,1000,10000,100000,1000000 7: JIT and no JIT, takes a while
bench/run.sh 100,100000 3   # a quick run
```

The JIT configuration is `-d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 -d opcache.file_update_protection=0`. The code under test is in included files, because the main script is never cached by OPcache and the JIT would not compile it. The run warms up, then proves that the JIT compiled something by watching `opcache.jit_buffer_size` shrink (261 KB used) and aborts otherwise.

**Without the JIT this library is 2 to 3 times slower than C for sorting by value on arrays of a thousand elements or more (and up to 4 times slower near a hundred elements).** The speed-ups below need the tracing JIT (`opcache.jit=tracing`, which is the default mode when `opcache.jit_buffer_size` is set), which is not enabled in most default PHP installations.

### `Sorter::create()->sort()` against `sort()`, JIT

| data | 10 | 100 | 1,000 | 10,000 | 100,000 | 1,000,000 |
|---|---:|---:|---:|---:|---:|---:|
| ints, many duplicates | 0.97x | 1.03x | 1.03x | 1.11x | 1.24x | 1.27x |
| ints, full range | 0.89x | 1.06x | 1.10x | 1.15x | 1.30x | 1.32x |
| floats | 0.82x | 0.80x | 0.88x | 0.93x | 1.04x | 1.05x |
| strings, random bytes 1-20 B | 0.56x | 0.47x | 0.62x | 0.61x | 0.68x | 0.72x |
| already sorted ints | 1.06x | 3.72x | 7.15x | 11.99x | 18.93x | 22.67x |
| reversed ints | 0.86x | 7.21x | 19.07x | 26.45x | 15.99x | 15.86x |
| numeric strings in several notations, many equal | 0.67x | 0.49x | 0.63x | 0.63x | 0.61x | 0.60x |
| floats with `-0.0` and many equal | 0.89x | 0.82x | 0.79x | 0.85x | 0.92x | 0.93x |

At 100,000 random ints this is 19.2 ms against 25.1 ms for `sort()`. It is slower than C on strings (the comparison is a PHP-level `strcmp()` call per step, against an inlined one in C) and on small arrays, where the call and the copy dominate. The `Sorter` and the bare function `Charm\Sort\Bench\sort(array &$a, int $flags = SORT_REGULAR): bool`, a drop-in `sort()` replica built on the same loops, run at the same speed (`bench/` prints both): the object API costs nothing measurable.

### Without the JIT

| data | 10 | 100 | 1,000 | 10,000 | 100,000 | 1,000,000 |
|---|---:|---:|---:|---:|---:|---:|
| ints, many duplicates | 0.39x | 0.23x | 0.34x | 0.38x | 0.44x | 0.45x |
| ints, full range | 0.51x | 0.23x | 0.34x | 0.37x | 0.44x | 0.44x |
| floats | 0.42x | 0.25x | 0.35x | 0.38x | 0.45x | 0.45x |
| strings, random bytes 1-20 B | 0.48x | 0.31x | 0.42x | 0.44x | 0.46x | 0.49x |
| already sorted ints | 0.58x | 2.11x | 3.78x | 6.37x | 9.99x | 11.95x |
| reversed ints | 0.34x | 3.05x | 6.26x | 8.75x | 8.65x | 9.15x |
| numeric strings, many equal | 0.64x | 0.44x | 0.52x | 0.49x | 0.49x | 0.50x |
| floats with `-0.0`, many equal | 0.45x | 0.24x | 0.35x | 0.35x | 0.40x | 0.40x |

Sorted and reversed input is detected with a linear scan, so those cases win even without the JIT.

### Comparison functions and `by()`, JIT

Speed relative to the native function, with the same `fn($a, $b) => $a <=> $b` on both sides (`strcmp(strtolower($x), strtolower($y))` against `by(strtolower(...))` in the fourth row), on random ints unless stated.

| | 10 | 100 | 1,000 | 10,000 | 100,000 | 1,000,000 |
|---|---:|---:|---:|---:|---:|---:|
| `using()` vs `usort()` | 1.14x | 1.58x | 1.64x | 1.61x | 1.40x | 1.29x |
| `using(preserveKeys: true)` vs `uasort()` | 0.82x | 1.41x | 1.52x | 1.47x | 1.19x | 1.04x |
| `using()->sortKeys()` vs `uksort()` | 0.90x | 1.40x | 1.47x | 1.37x | 1.21x | 1.15x |
| `by(strtolower(...))` vs `usort()` with `strtolower` in the function | 1.40x | 2.71x | 2.92x | 2.78x | 2.76x | 2.35x |
| `using()` vs `usort()`, already sorted | 1.57x | 6.71x | 12.34x | 18.98x | 21.31x | 25.57x |

Without the JIT, `using()` is 0.6 to 0.7x of `usort()` (the user function call dominates and PHP's own loop around it is faster), while `by()` is still 1.7 to 2.0x faster than `usort()` with the work in the function, from 100 elements up, and sorted input is 3 to 12x faster.

### `sortKeys()` against `ksort()`, JIT

| keys | 10 | 100 | 1,000 | 10,000 | 100,000 | 1,000,000 |
|---|---:|---:|---:|---:|---:|---:|
| int keys | 0.43x | 0.67x | 0.78x | 0.80x | 0.78x | 0.72x |
| non-numeric string keys | 0.38x | 0.43x | 0.55x | 0.57x | 0.60x | 0.58x |

`sortKeys()` is slower than `ksort()`: the result has to be rebuilt in key order (about 11 ms of 92 ms for 100,000 string keys) on top of the sort. Without the JIT it is 0.2 to 0.4x. It exists for API completeness and for `using()` / `by()` / `collated()` with keys, not for speed.

## How it works

The sort for the built-in comparisons is a stable quicksort (Hoare partitioning, median of three) on the pair (value, original offset). The offset is only compared when two values are equal. Partitions of 17 or fewer elements are left for one final insertion pass, arrays of up to 16 elements use a plain insertion sort, and sorted or strictly reversed arrays are detected up front. Comparison functions use a stable merge sort instead, because there the number of calls to the function is what costs: a merge sort needs about n log n of them, a quicksort about 40% more, and the merge of two runs that are already in order is skipped.

For the speed to survive the JIT, the loops are generated: `bin/generate` expands `bin/loop.tpl`, `bin/keys.tpl` and `bin/merge.tpl` into `src/Loops.php`, one loop per direction and comparison, with the comparison written inline (a closure or function call per comparison costs more than the whole sort). The generated file is committed, and a test checks that it is up to date.

## Development

```
composer install
composer test        # phpunit
composer generate    # regenerate src/Loops.php after editing bin/*.tpl
composer bench       # the benchmark above
```

Edit `bin/*.tpl` and `bin/generate`, never `src/Loops.php`. `research/` holds the design notes and the experiments the code grew from.

## License

MIT.
