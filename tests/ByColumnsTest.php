<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

final class ByColumnsTest extends TestCase
{
    public function testArrays(): void
    {
        $people = [
            ['name' => 'Kari', 'age' => 30],
            ['name' => 'Ola', 'age' => 41],
            ['name' => 'Anne', 'age' => 30],
            ['name' => 'Per', 'age' => 41],
        ];
        $sorted = Sorter::byColumns(['age' => Order::Descending, 'name' => Order::Ascending])->sort($people);
        $this->assertSame(['Ola', 'Per', 'Anne', 'Kari'], array_column($sorted, 'name'));
    }

    public function testObjects(): void
    {
        $people = [];
        foreach ([['Kari', 30], ['Ola', 41], ['Anne', 30]] as [$name, $age]) {
            $people[] = new class ($name, $age) {
                public function __construct(public string $name, public int $age)
                {
                }
            };
        }
        $sorted = Sorter::byColumns(['age' => Order::Ascending, 'name' => Order::Descending])->sort($people);
        $this->assertSame(['Kari', 'Anne', 'Ola'], array_map(fn($p) => $p->name, $sorted));
    }

    public function testAgainstReference(): void
    {
        foreach ([0, 1, 2, 17, 18, 300, 2000] as $n) {
            for ($seed = 0; $seed < 5; ++$seed) {
                mt_srand($n * 13 + $seed);
                $rows = [];
                for ($i = 0; $i < $n; ++$i) {
                    $rows[] = ['a' => mt_rand(0, 3), 'b' => Data::word('xyz', 1, 2), 'c' => mt_rand(0, 2) / 2, 'id' => $i];
                }
                $directions = [
                    ['a' => Order::Ascending, 'b' => Order::Descending, 'c' => Order::Ascending],
                    ['b' => Order::Descending, 'a' => Order::Descending],
                    ['c' => Order::Descending],
                ];
                foreach ($directions as $columns) {
                    $expected = $rows;
                    usort($expected, function ($x, $y) use ($columns) {
                        foreach ($columns as $col => $order) {
                            if (($c = $x[$col] <=> $y[$col]) !== 0) {
                                return $order === Order::Ascending ? $c : -$c;
                            }
                        }
                        return 0; // ties keep their order: usort() is stable
                    });
                    $this->assertSame($expected, Sorter::byColumns($columns)->sort($rows), "n=$n seed=$seed");
                }
            }
        }
    }

    public function testPreserveKeysAndMissingColumns(): void
    {
        $rows = ['x' => ['a' => 2], 'y' => ['a' => 1], 'z' => ['b' => 9]];
        $sorter = Sorter::byColumns(['a' => Order::Ascending]);
        $this->assertSame([['b' => 9], ['a' => 1], ['a' => 2]], $sorter->sort($rows), 'a missing column is null, which sorts first');
    }
}
