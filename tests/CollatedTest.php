<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Order;
use Charm\Sort\Sorter;
use PHPUnit\Framework\TestCase;

final class CollatedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl is not loaded');
        }
    }

    public function testNorwegianNames(): void
    {
        $names = ['Åse', 'Zorro', 'Ole', 'Ærlig', 'Øystein', 'Anne', 'Aase', 'Eva'];
        $collator = new \Collator('nb_NO');
        $expected = $names;
        $collator->sort($expected, \Collator::SORT_STRING);
        $this->assertSame($expected, Sorter::collated($collator)->sort($names));
        // In Norwegian, Æ, Ø and Å come after Z, and "Aa" counts as Å.
        $this->assertSame(['Anne', 'Eva', 'Ole', 'Zorro', 'Ærlig', 'Øystein', 'Åse', 'Aase'], Sorter::collated($collator)->sort($names));
        $this->assertSame(array_reverse($expected), Sorter::collated($collator, order: Order::Descending)->sort($names));
    }

    public function testKeyFunctionAndNumericLookingText(): void
    {
        $collator = new \Collator('en_US');
        $rows = [['n' => '10'], ['n' => '9'], ['n' => '100'], ['n' => 'b'], ['n' => 'a']];
        $expected = array_column($rows, 'n');
        $collator->sort($expected, \Collator::SORT_STRING);
        $sorted = Sorter::collated($collator, fn($r) => $r['n'])->sort($rows);
        $this->assertSame($expected, array_column($sorted, 'n'));
    }

    public function testAgainstCollatorSort(): void
    {
        $collator = new \Collator('nb_NO');
        $sorter = Sorter::collated($collator);
        foreach ([2, 17, 18, 300] as $n) {
            mt_srand($n);
            $letters = mb_str_split('aeoæøåAEOÆØÅbk');
            $names = [];
            while (count($names) < $n) {
                $name = '';
                for ($i = mt_rand(1, 6); $i > 0; --$i) {
                    $name .= $letters[mt_rand(0, count($letters) - 1)];
                }
                $names[$name] = true;
            }
            $names = array_keys($names);
            $expected = $names;
            $collator->sort($expected, \Collator::SORT_STRING); // distinct strings: the order is unique; SORT_STRING because the default compares numeric strings as numbers
            $this->assertSame($expected, $sorter->sort($names), "n=$n");
        }
    }
}
