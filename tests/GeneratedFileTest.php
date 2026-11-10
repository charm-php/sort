<?php

declare(strict_types=1);

namespace Charm\Sort\Tests;

use PHPUnit\Framework\TestCase;

final class GeneratedFileTest extends TestCase
{
    public function testLoopsAreUpToDate(): void
    {
        $root = dirname(__DIR__);
        $generated = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/bin/generate") . ' --stdout');
        $this->assertSame(file_get_contents("$root/src/Loops.php"), $generated, 'src/Loops.php is stale: run bin/generate');
    }
}
