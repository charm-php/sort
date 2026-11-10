<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

/** A comparison can run user code; that code may use the same Sorter. */
final class ReentrancyTest extends TestCase
{
    private static function sorting(Sorter $sorter, array $other, int &$calls, string $text): object
    {
        return new class ($sorter, $other, $calls, $text) implements \Stringable {
            public function __construct(private Sorter $sorter, private array $other, private int &$calls, private string $text)
            {
            }

            public function __toString(): string
            {
                ++$this->calls;
                $sorted = $this->sorter->sort($this->other);
                $expected = $this->other;
                sort($expected);
                if ($sorted !== $expected) {
                    throw new \LogicException('nested sort gave a wrong result');
                }
                return $this->text;
            }
        };
    }

    public function testToStringSortsAnotherArrayInsideTheComparison(): void
    {
        $sorter = Sorter::create();
        mt_srand(5);
        $other = array_map(fn() => mt_rand(0, 1000), range(1, 500));
        $calls = 0;
        // Regular comparison of an object with a string calls __toString() inside the sort loop.
        $values = [];
        $expected = [];
        for ($i = 0; $i < 200; ++$i) {
            $text = Data::word('abcdef', 3, 8);
            $values[] = $i % 2 ? self::sorting($sorter, $other, $calls, $text) : $text;
            $expected[] = $text;
        }
        $sorted = $sorter->sort($values);
        $this->assertGreaterThan(200, $calls);
        $this->assertSame(array_map('strval', $sorted), (static function () use ($expected) {
            sort($expected, SORT_STRING);
            return $expected;
        })());
    }

    public function testToStringDuringTheConversionToKeys(): void
    {
        foreach ([Comparison::Binary, Comparison::Natural] as $comparison) {
            $sorter = Sorter::create($comparison);
            $calls = 0;
            $other = [5, 3, 9, 1];
            $values = [];
            foreach (['b', 'c', 'a'] as $text) {
                $values[] = self::sorting($sorter, $other, $calls, $text);
            }
            $this->assertSame(['a', 'b', 'c'], array_map('strval', $sorter->sort($values)));
            $this->assertGreaterThanOrEqual(3, $calls);
        }
    }
}
