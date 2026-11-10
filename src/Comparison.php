<?php

declare(strict_types=1);

namespace Charm\Sort;

/**
 * How two values (or sort keys) are compared. Each case corresponds to a flag of PHP's `sort()`.
 */
enum Comparison
{
    /** PHP's standard comparison, like `SORT_REGULAR`: numeric strings compare as numbers. */
    case Regular;

    /** Bytewise string comparison, like `SORT_STRING`. Values are converted to strings first. */
    case Binary;

    /** Bytewise, ASCII case-insensitive, like `SORT_STRING | SORT_FLAG_CASE`. */
    case BinaryCaseInsensitive;

    /** Comparison as floats, like `SORT_NUMERIC`. Values are converted to float first. */
    case Numeric;

    /** "Natural order" (`img2` before `img10`), like `SORT_NATURAL`. Values are converted to strings first. */
    case Natural;

    /** Natural order, ASCII case-insensitive, like `SORT_NATURAL | SORT_FLAG_CASE`. */
    case NaturalCaseInsensitive;
}
