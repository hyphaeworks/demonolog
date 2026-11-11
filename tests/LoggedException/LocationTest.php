<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\LoggedException;

use Hyphaeworks\Demonolog\LoggedException\Location;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocationTest extends TestCase
{
    #[Test]
    public function parsesFileAndLine(): void
    {
        self::assertEquals(new Location('/app/src/Checkout.php', 14), Location::parse('/app/src/Checkout.php:14'));
        self::assertEquals(new Location('C:\app\src\Checkout.php', 14), Location::parse('C:\app\src\Checkout.php:14'));
    }

    #[Test]
    public function keepsTextWithoutALineAsTheFile(): void
    {
        self::assertEquals(new Location('Unknown', null), Location::parse('Unknown'));
    }

    #[Test]
    public function readsLineZeroOfAnInternalFunctionAsNoLine(): void
    {
        self::assertEquals(new Location('internal[{closure}]', null), Location::fromLog('internal[{closure}]', 0));
        self::assertEquals(new Location('internal[{closure}]', null), Location::parse('internal[{closure}]:0'));
    }

    #[Test]
    public function readsAnEmptyFileAsNoLocation(): void
    {
        self::assertNull(Location::fromLog('', 3));
        self::assertNull(Location::parse(''));
    }

    #[Test]
    public function rejectsAnEmptyFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A location has a file');

        new Location('', 3);
    }

    #[Test]
    public function rejectsALineBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Line 0 of /app/src/Checkout.php is not a line');

        new Location('/app/src/Checkout.php', 0);
    }
}
