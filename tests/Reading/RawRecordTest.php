<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Reading;

use Hyphaeworks\Demonolog\Reading\RawRecord;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RawRecordTest extends TestCase
{
    #[Test]
    public function rejectsANegativeOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RawRecord(-1, '[2026-11-09 10:15:30] app.INFO: Hello [] []');
    }
}
