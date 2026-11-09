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
        self::assertEquals(new Location('Unknown', 0), Location::parse('Unknown'));
    }

    #[Test]
    public function rejectsANegativeLine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Location('/app/src/Checkout.php', -1);
    }
}
