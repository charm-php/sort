<?php

declare(strict_types=1);

namespace Charm\Sort;

/**
 * The direction of a sort.
 *
 * Both directions are stable: elements that compare equal keep their original order, also
 * when sorting descending (this is what PHP's `rsort()` does).
 */
enum Order
{
    /** Smallest first, like `sort()`. */
    case Ascending;

    /** Largest first, like `rsort()`. */
    case Descending;
}
