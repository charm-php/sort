<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

final class VersionsTest extends TestCase
{
    public function testExamples(): void
    {
        $this->assertSame(['1.0.0-beta', '1.0.0', '1.9', '1.10'], Sorter::versions()->sort(['1.10', '1.9', '1.0.0', '1.0.0-beta']));
        $this->assertSame(['1.10', '1.9'], Sorter::versions(Order::Descending)->sort(['1.9', '1.10']));
        $this->assertSame([], Sorter::versions()->sort([]));
    }

    public function testAgainstVersionCompare(): void
    {
        $parts = ['0', '1', '2', '9', '10', '1.0', '1.0.0', '2.1', '1.0.0-beta', '1.0-rc1', '1.0RC2', '1.0pl1', '1.0-dev', '1.0alpha', '1.0b'];
        foreach ([Order::Ascending, Order::Descending] as $order) {
            foreach ([2, 17, 100, 500] as $n) {
                mt_srand($n);
                $input = [];
                for ($i = 0; $i < $n; ++$i) {
                    $input[] = $parts[mt_rand(0, count($parts) - 1)] . ($i % 3 === 0 ? '' : '.' . mt_rand(0, 12));
                }
                $expected = $input;
                usort($expected, fn($a, $b) => $order === Order::Ascending ? version_compare($a, $b) : version_compare($b, $a));
                $this->assertSame($expected, Sorter::versions($order)->sort($input), "{$order->name} n=$n");
            }
        }
    }

    public function testPreserveKeysViaSortKeys(): void
    {
        $this->assertSame(['1.2' => 'a', '1.10' => 'b'], Sorter::versions()->sortKeys(['1.10' => 'b', '1.2' => 'a']));
    }
}
