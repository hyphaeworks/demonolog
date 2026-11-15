<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\LoggedException;

use Hyphaeworks\Demonolog\LoggedException\ApplicationFrames;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationFramesTest extends TestCase
{
    #[Test]
    public function rejectsAnEmptyVendorPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A vendor path is not empty');

        new ApplicationFrames(['vendor/', '']);
    }
}
