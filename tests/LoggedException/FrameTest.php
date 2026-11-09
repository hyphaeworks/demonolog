<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\LoggedException;

use Hyphaeworks\Demonolog\LoggedException\CallType;
use Hyphaeworks\Demonolog\LoggedException\Frame;
use Hyphaeworks\Demonolog\LoggedException\Location;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    #[Test]
    public function describesACall(): void
    {
        $frame = Frame::call(2, new Location('/app/src/Kernel.php', 12), 'App\Kernel', CallType::Instance, 'boot', "'dev'", true);

        self::assertSame(2, $frame->index);
        self::assertSame('/app/src/Kernel.php', $frame->location?->file);
        self::assertSame('App\Kernel', $frame->class);
        self::assertSame(CallType::Instance, $frame->callType);
        self::assertSame('boot', $frame->function);
        self::assertSame("'dev'", $frame->arguments);
        self::assertTrue($frame->inApp);
    }

    #[Test]
    public function describesTheMainScript(): void
    {
        $frame = Frame::main(5, false);

        self::assertSame('{main}', $frame->function);
        self::assertNull($frame->location);
        self::assertNull($frame->class);
        self::assertNull($frame->arguments);
    }

    #[Test]
    public function describesALocationWithoutACall(): void
    {
        $frame = Frame::at(0, new Location('/app/src/Kernel.php', 12), true);

        self::assertSame(12, $frame->location?->line);
        self::assertNull($frame->function);
    }

    #[Test]
    public function rejectsAClassWithoutACallType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Frame::call(0, null, 'App\Kernel', null, 'boot', '', true);
    }

    #[Test]
    public function rejectsACallTypeWithoutAClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Frame::call(0, null, null, CallType::Static, 'boot', '', true);
    }

    #[Test]
    public function rejectsANegativeIndex(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Frame::main(-1, false);
    }
}
