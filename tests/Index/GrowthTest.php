<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Index;

use Hyphaeworks\Demonolog\Index\Growth;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GrowthTest extends TestCase
{
    #[Test]
    public function cannotHaveIndexedMoreBytesThanTheFileHas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Indexed 11 of 10 bytes');

        new Growth(restarted: false, indexedBytes: 11, fileBytes: 10);
    }

    #[Test]
    public function cannotHaveIndexedANegativeNumberOfBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Indexed -1 of 10 bytes');

        new Growth(restarted: false, indexedBytes: -1, fileBytes: 10);
    }
}
