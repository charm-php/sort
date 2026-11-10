
    /**
     * {{DOC}}
     *
     * Sorts the list $arr of unique keys in place. Unique keys that compare as different need no
     * stability: no offset array and no tie-break, so this is a plain quicksort.
     *
     * @param list<int|string> $arr
     */
    public static function {{NAME}}(array &$arr): void
    {
        $n = \count($arr);
        if ($n < 2) {
            return;
        }

        $a = $arr;
        $arr = null;

        // Local (not static) partition stacks, like the stable loops.
        $stackLow = [];
        $stackHigh = [];

        $low = 0;
        $high = $n - 1;
        $sp = 0;

        while (true) {
            while ($high - $low > 16) {
                $mid = ($low + $high) >> 1;

                // Median of three, sorted in place: a[low] <= a[mid] <= a[high].
                $x = $a[$low];
                $y = $a[$mid];
                if (@after($x, $y)) {
                    $a[$low] = $y;
                    $a[$mid] = $x;
                    $y = $x;
                }
                $z = $a[$high];
                if (@after($y, $z)) {
                    $a[$mid] = $z;
                    $a[$high] = $y;
                    $y = $z;
                    $x = $a[$low];
                    if (@after($x, $y)) {
                        $a[$low] = $y;
                        $a[$mid] = $x;
                        $y = $x;
                    }
                }
                $p = $y;

                // Hoare partition; a[low] and a[high] are the sentinels.
                $i = $low + 1;
                $j = $high - 1;
                while (true) {
                    while (@before($a[$i], $p)) {
                        ++$i;
                    }
                    while (@after($a[$j], $p)) {
                        --$j;
                    }
                    if ($i >= $j) {
                        break;
                    }
                    $t = $a[$i];
                    $a[$i] = $a[$j];
                    $a[$j] = $t;
                    ++$i;
                    --$j;
                }

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

        // The first key in order is within the first 17 offsets; move it to offset 0 as the sentinel.
        $m = 0;
        $mv = $a[0];
        $limit = $n < 17 ? $n : 17;
        for ($i = 1; $i < $limit; ++$i) {
            if (@before($a[$i], $mv)) {
                $m = $i;
                $mv = $a[$i];
            }
        }
        if ($m !== 0) {
            $a[$m] = $a[0];
            $a[0] = $mv;
        }

        // Final insertion pass; a[0] is first in order, so no bounds check.
        for ($i = 2; $i < $n; ++$i) {
            $v = $a[$i];
            $j = $i - 1;
            if (@after($a[$j], $v)) {
                do {
                    $a[$j + 1] = $a[$j];
                    --$j;
                } while (@after($a[$j], $v));
                $a[$j + 1] = $v;
            }
        }

        $arr = $a;
    }
