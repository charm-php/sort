
    /**
     * {{DOC}}
     *
     * Stable bottom-up merge sort of the list $arr in place with a user comparison function, which is
     * what costs most here: a merge sort calls it fewer times than a quicksort (about n log n - n), and
     * it skips the merge of two runs that are already in order, and a sorted array costs n - 1 calls. Returns the original offset of every
     * sorted element.
     *
     * @param list<mixed> $arr
     * @return list<int>
     */
    public static function {{NAME}}(array &$arr, \Closure $cmp): array
    {
        $n = \count($arr);
        if ($n < 2) {
            return $n === 1 ? [0] : [];
        }

        $a = $arr;
        $arr = null;

        // Already in order: n - 1 calls and no moves. A random array leaves the scan at the first
        // pairs that are out of order.
        for ($i = 1; $i < $n; ++$i) {
            @cmp($a[$i - 1], $a[$i])
            if ($c {{GT}}) {
                break;
            }
        }
        if ($i === $n) {
            $arr = $a;
            return \range(0, $n - 1);
        }

        $ax = \range(0, $n - 1);

        // Insertion sort runs of 8 in place.
        for ($start = 0; $start < $n; $start += 8) {
            $end = $start + 8 < $n ? $start + 8 : $n;
            for ($i = $start + 1; $i < $end; ++$i) {
                $v = $a[$i];
                $vi = $ax[$i];
                $j = $i - 1;
                @cmp($a[$j], $v)
                if ($c {{GT}}) {
                    do {
                        $a[$j + 1] = $a[$j];
                        $ax[$j + 1] = $ax[$j];
                        --$j;
                        if ($j < $start) {
                            break;
                        }
                        @cmp($a[$j], $v)
                    } while ($c {{GT}});
                    $a[$j + 1] = $v;
                    $ax[$j + 1] = $vi;
                }
            }
        }

        // Merge passes, ping-pong between ($a, $ax) and ($b, $bx); the first write to $b separates it.
        $b = $a;
        $bx = $ax;
        for ($width = 8; $width < $n; $width <<= 1) {
            for ($low = 0; $low < $n; $low += 2 * $width) {
                $mid = $low + $width;
                $end = $mid + $width < $n ? $mid + $width : $n;
                if ($mid < $n) {
                    @cmp($a[$mid - 1], $a[$mid])
                }
                // A lone run, or two runs already in order: copy.
                if ($mid >= $n || $c {{LE}}) {
                    for ($k = $low; $k < $end; ++$k) {
                        $b[$k] = $a[$k];
                        $bx[$k] = $ax[$k];
                    }
                    continue;
                }
                $i = $low;
                $j = $mid;
                $k = $low;
                $l = $a[$i];
                $r = $a[$j];
                while (true) {
                    // The left element wins ties: that is the stability.
                    @cmp($l, $r)
                    if ($c {{LE}}) {
                        $b[$k] = $l;
                        $bx[$k] = $ax[$i];
                        ++$k;
                        if (++$i === $mid) {
                            break;
                        }
                        $l = $a[$i];
                    } else {
                        $b[$k] = $r;
                        $bx[$k] = $ax[$j];
                        ++$k;
                        if (++$j === $end) {
                            break;
                        }
                        $r = $a[$j];
                    }
                }
                // One side is used up; copy the rest of the other.
                while ($i < $mid) {
                    $b[$k] = $a[$i];
                    $bx[$k] = $ax[$i];
                    ++$i;
                    ++$k;
                }
                while ($j < $end) {
                    $b[$k] = $a[$j];
                    $bx[$k] = $ax[$j];
                    ++$j;
                    ++$k;
                }
            }
            $t = $a;
            $a = $b;
            $b = $t;
            $t = $ax;
            $ax = $bx;
            $bx = $t;
            $t = null;
        }

        $arr = $a;
        return $ax;
    }
