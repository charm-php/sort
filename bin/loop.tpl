
    /**
     * {{DOC}}
     *
     * Sorts the list $arr in place, stable. With $perm true it also returns the original offset of
     * every sorted element, so the caller can arrange other data the same way; with $perm false the
     * return value is meaningless.
     *
     * @param list<mixed> $arr
     * @return list<int>
     */
    public static function {{NAME}}(array &$arr, bool $perm): array
    {
        $n = \count($arr);
        if ($n < 2) {
            return $perm && $n === 1 ? [0] : [];
        }

        // Work on a local copy outside the reference; $arr = null leaves $a with refcount 1,
        // so the writes below don't copy the array.
        $a = $arr;
        $arr = null;

        // Tiny arrays: a plain stable insertion sort. The quicksort's setup (offset array,
        // stacks) costs more than it saves here.
        if ($n <= 16) {
            if ($perm) {
                $ix = range(0, $n - 1);
                for ($i = 1; $i < $n; ++$i) {
                    $v = $a[$i];
                    $vi = $ix[$i];
                    $j = $i - 1;
                    if (@after($a[$j], $v)) {
                        do {
                            $a[$j + 1] = $a[$j];
                            $ix[$j + 1] = $ix[$j];
                            --$j;
                        } while ($j >= 0 && @after($a[$j], $v));
                        $a[$j + 1] = $v;
                        $ix[$j + 1] = $vi;
                    }
                }
                $arr = $a;
                return $ix;
            }
            for ($i = 1; $i < $n; ++$i) {
                $v = $a[$i];
                $j = $i - 1;
                if (@after($a[$j], $v)) {
                    do {
                        $a[$j + 1] = $a[$j];
                        --$j;
                    } while ($j >= 0 && @after($a[$j], $v));
                    $a[$j + 1] = $v;
                }
            }
            $arr = $a;
            return [];
        }

        // Already sorted (equal elements are fine, they stay where they are) or strictly
        // reversed (no equal elements, so reversing is the stable order). Random input leaves
        // both scans after a few comparisons.
        $i = 1;
        while ($i < $n && !@after($a[$i - 1], $a[$i])) {
            ++$i;
        }
        if ($i === $n) {
            $arr = $a;
            return $perm ? range(0, $n - 1) : [];
        }
        if ($i === 1) {
            while ($i < $n && @after($a[$i - 1], $a[$i])) {
                ++$i;
            }
            if ($i === $n) {
                $arr = array_reverse($a);
                return $perm ? range($n - 1, 0) : [];
            }
        }

        // Stable quicksort: Hoare partitioning on the key (value, original offset). Keys are
        // unique, so any correct algorithm gives the stable order. The offset is only compared
        // when two values are equal; it lives in a parallel packed array that moves with the values.
        $ix = range(0, $n - 1);

        // Local (not static) partition stacks: a comparison can run user code (__toString()),
        // which could call this function again. Depth stays below log2(n) + 1.
        $stackLow = [];
        $stackHigh = [];

        $low = 0;
        $high = $n - 1;
        $sp = 0;

        while (true) {
            while ($high - $low > 16) {
                $mid = ($low + $high) >> 1;

                // Median of three on (value, offset), sorted in place: key(low) <= key(mid) <= key(high).
                $va = $a[$low];
                $ia = $ix[$low];
                $vb = $a[$mid];
                $ib = $ix[$mid];
                if (@gt($va, $ia, $vb, $ib)) {
                    $a[$low] = $vb;
                    $ix[$low] = $ib;
                    $a[$mid] = $va;
                    $ix[$mid] = $ia;
                    $vb = $va;
                    $ib = $ia;
                }
                $vc = $a[$high];
                $ic = $ix[$high];
                if (@gt($vb, $ib, $vc, $ic)) {
                    $a[$mid] = $vc;
                    $ix[$mid] = $ic;
                    $a[$high] = $vb;
                    $ix[$high] = $ib;
                    $vb = $vc;
                    $ib = $ic;
                    $va = $a[$low];
                    $ia = $ix[$low];
                    if (@gt($va, $ia, $vb, $ib)) {
                        $a[$low] = $vb;
                        $ix[$low] = $ib;
                        $a[$mid] = $va;
                        $ix[$mid] = $ia;
                        $vb = $va;
                        $ib = $ia;
                    }
                }
                $p = $vb;
                $pi = $ib;

                // Hoare partition; key(low) and key(high) are the sentinels.
                $i = $low + 1;
                $j = $high - 1;
                while (true) {
                    while (@lt($x = $a[$i], $ix[$i], $p, $pi)) {
                        ++$i;
                    }
                    while (@gt($y = $a[$j], $ix[$j], $p, $pi)) {
                        --$j;
                    }
                    if ($i >= $j) {
                        break;
                    }
                    // The scans left the two values in $x and $y.
                    $a[$i] = $y;
                    $a[$j] = $x;
                    $t = $ix[$i];
                    $ix[$i] = $ix[$j];
                    $ix[$j] = $t;
                    ++$i;
                    --$j;
                }

                // Recurse into the smaller part, push the larger one; parts of 17 or fewer
                // elements are left for the final insertion pass.
                if (($j - $low) < ($high - $i)) {
                    if ($high - $i > 16) {
                        $stackLow[$sp] = $i;
                        $stackHigh[$sp] = $high;
                        ++$sp;
                    }
                    $high = $j;
                } else {
                    if ($j - $low > 16) {
                        $stackLow[$sp] = $low;
                        $stackHigh[$sp] = $j;
                        ++$sp;
                    }
                    $low = $i;
                }
            }

            if ($sp === 0) {
                break;
            }
            --$sp;
            $low = $stackLow[$sp];
            $high = $stackHigh[$sp];
        }

        // The smallest key is within the first 17 offsets; move it to offset 0 as the sentinel.
        $m = 0;
        $mv = $a[0];
        $mi = $ix[0];
        for ($i = 1; $i < 17; ++$i) {
            $v = $a[$i];
            if (@lt($v, $ix[$i], $mv, $mi)) {
                $m = $i;
                $mv = $v;
                $mi = $ix[$i];
            }
        }
        if ($m !== 0) {
            $a[$m] = $a[0];
            $ix[$m] = $ix[0];
            $a[0] = $mv;
            $ix[0] = $mi;
        }

        // Final insertion pass over the whole array; key(0) is the smallest, so no bounds check.
        for ($i = 2; $i < $n; ++$i) {
            $v = $a[$i];
            $vi = $ix[$i];
            $j = $i - 1;
            $w = $a[$j];
            if (@gt($w, $ix[$j], $v, $vi)) {
                do {
                    $a[$j + 1] = $w;
                    $ix[$j + 1] = $ix[$j];
                    --$j;
                    $w = $a[$j];
                } while (@gt($w, $ix[$j], $v, $vi));
                $a[$j + 1] = $v;
                $ix[$j + 1] = $vi;
            }
        }

        $arr = $a;
        return $ix;
    }
