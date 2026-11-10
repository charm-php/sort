<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use Charm\Sort\Comparison;
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

    public function testNorwegian(): void
    {
        $names = ['Åse', 'Zorro', 'Ole', 'Ærlig', 'Øystein', 'Anne', 'Aase', 'Eva'];
        $collator = new \Collator('nb_NO');
        // In Norwegian, Æ, Ø and Å come after Z, and "Aa" counts as Å.
        $this->assertSame(['Anne', 'Eva', 'Ole', 'Zorro', 'Ærlig', 'Øystein', 'Åse', 'Aase'], Sorter::collated($collator)->sort($names));
        $this->assertSame(array_reverse(['Anne', 'Eva', 'Ole', 'Zorro', 'Ærlig', 'Øystein', 'Åse', 'Aase']), Sorter::collated($collator, Order::Descending)->sort($names));
    }

    public function testGerman(): void
    {
        $names = ['Zebra', 'Äpfel', 'Apfel', 'Österreich', 'Ofen', 'Uhr', 'Über', 'ärger', 'Arm'];
        $collator = new \Collator('de_DE');
        $expected = $names;
        $collator->sort($expected, \Collator::SORT_STRING);
        $this->assertSame($expected, Sorter::collated($collator)->sort($names));
        // In German, Ä sorts with A (unlike in Norwegian), so Äpfel comes right after Apfel.
        $sorted = Sorter::collated($collator)->sort($names);
        $this->assertLessThan(array_search('Arm', $sorted), array_search('Äpfel', $sorted));
    }

    public function testEqualsUsingCollatorCompareAndCollatorSort(): void
    {
        foreach (['nb_NO', 'de_DE', 'en_US'] as $locale) {
            $collator = new \Collator($locale);
            $letters = mb_str_split('aeoæøåäöüAEOÆØÅbkzZ');
            foreach ([2, 17, 18, 300] as $n) {
                mt_srand($n);
                $names = [];
                while (count($names) < $n) {
                    $name = '';
                    for ($i = mt_rand(1, 6); $i > 0; --$i) {
                        $name .= $letters[mt_rand(0, count($letters) - 1)];
                    }
                    $names[$name] = true;
                }
                $names = array_keys($names);
                // SORT_STRING: the default flag compares numeric strings as numbers.
                $expected = $names;
                $collator->sort($expected, \Collator::SORT_STRING);
                $this->assertSame($expected, Sorter::collated($collator)->sort($names), "$locale n=$n");
                $this->assertSame($expected, Sorter::using($collator->compare(...))->sort($names), "$locale n=$n using()");

                // preserveKeys against asort().
                $keyed = Data::withStringKeys($names);
                $expectedKeyed = $keyed;
                $collator->asort($expectedKeyed, \Collator::SORT_STRING);
                $this->assertSame(serialize($expectedKeyed), serialize(Sorter::collated($collator, preserveKeys: true)->sort($keyed)), "$locale n=$n asort");
            }
        }
    }

    public function testNumericLookingTextIsCollatedAsText(): void
    {
        // The reason collated() compares the sort keys as bytes: "10" must come before "9".
        $collator = new \Collator('en_US');
        $this->assertSame(['10', '100', '9', 'a'], Sorter::collated($collator)->sort(['9', 'a', '100', '10']));
    }

    public function testSortKeysCollatesTheKeys(): void
    {
        $collator = new \Collator('nb_NO');
        $input = ['Åse' => 1, 'Zorro' => 2, 'Ole' => 3, 'Ærlig' => 4];
        $this->assertSame(['Ole', 'Zorro', 'Ærlig', 'Åse'], array_keys(Sorter::collated($collator)->sortKeys($input)));
        $this->assertSame([3, 2, 4, 1], array_values(Sorter::collated($collator)->sortKeys($input)));
    }

    public function testNonStringValuesAreConvertedToStrings(): void
    {
        $collator = new \Collator('en_US');
        // Collation is of the text: "10" sorts before "2".
        $this->assertSame([1, 10, 2], Sorter::collated($collator)->sort([2, 10, 1]));
    }

    public function testInvalidUtf8ThrowsAValueErrorNamingTheElement(): void
    {
        $collator = new \Collator('nb_NO');
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('"bad � value"');
        Sorter::collated($collator)->sort(['fine', "bad \xff value", 'also fine']);
    }
}
