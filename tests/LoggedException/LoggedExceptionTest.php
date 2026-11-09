<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\LoggedException;

use Hyphaeworks\Demonolog\LoggedException\Location;
use Hyphaeworks\Demonolog\LoggedException\LoggedException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoggedExceptionTest extends TestCase
{
    #[Test]
    public function onlyTheInnermostExceptionCanBeTruncated(): void
    {
        $inner = new LoggedException('LogicException', 'Inner', 0, new Location('/app/src/B.php', 2));

        $this->expectException(InvalidArgumentException::class);

        new LoggedException('RuntimeException', 'Outer', 0, new Location('/app/src/A.php', 1), previous: $inner, truncated: true);
    }
}
