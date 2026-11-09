<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Format\Line;

use Hyphaeworks\Demonolog\Format\Line\ExceptionText;
use Hyphaeworks\Demonolog\LoggedException\CallType;
use Hyphaeworks\Demonolog\LoggedException\Frame;
use Hyphaeworks\Demonolog\LoggedException\Location;
use Hyphaeworks\Demonolog\LoggedException\LoggedException;
use Hyphaeworks\Demonolog\LoggedException\PhpStacktrace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionTextTest extends TestCase
{
    #[Test]
    public function parsesTheExceptionHeader(): void
    {
        $exception = (new ExceptionText())->parse(
            '[object] (RuntimeException(code: 42): Order 7 failed at checkout at /app/src/Checkout.php:88)',
        );

        self::assertNotNull($exception);
        self::assertSame('RuntimeException', $exception->type);
        self::assertSame(42, $exception->code);
        self::assertSame('Order 7 failed at checkout', $exception->message);
        self::assertEquals(new Location('/app/src/Checkout.php', 88), $exception->location);
        self::assertSame([], $exception->frames());
        self::assertNull($exception->previous);
        self::assertNull($exception->stacktrace);
        self::assertFalse($exception->truncated);
    }

    #[Test]
    public function parsesTheStacktraceIntoFrames(): void
    {
        $stacktrace = <<<'TRACE'
            #0 /app/vendor/symfony/http-kernel/HttpKernel.php(183): App\Controller\CheckoutController->submit(Object(Symfony\Component\HttpFoundation\Request), 'order-7')
            #1 [internal function]: App\Service\Mailer::send(Array)
            #2 /app/src/Kernel.php(12): {closure:App\Kernel::boot():11}()
            #3 {main}
            TRACE;

        $exception = (new ExceptionText())->parse(
            "[object] (RuntimeException(code: 0): Boom at /app/src/Checkout.php:88)\n[stacktrace]\n" . $stacktrace . "\n",
        );

        self::assertNotNull($exception);
        self::assertSame('Boom', $exception->message);
        self::assertInstanceOf(PhpStacktrace::class, $exception->stacktrace);
        self::assertSame($stacktrace, $exception->stacktrace->text);
        self::assertEquals(
            [
                Frame::call(0, new Location('/app/vendor/symfony/http-kernel/HttpKernel.php', 183), 'App\Controller\CheckoutController', CallType::Instance, 'submit', "Object(Symfony\Component\HttpFoundation\Request), 'order-7'", false),
                Frame::call(1, null, 'App\Service\Mailer', CallType::Static, 'send', 'Array', false),
                Frame::call(2, new Location('/app/src/Kernel.php', 12), null, null, '{closure:App\Kernel::boot():11}', '', true),
                Frame::main(3, false),
            ],
            $exception->frames(),
        );
    }

    #[Test]
    public function survivesSerialization(): void
    {
        $exception = (new ExceptionText())->parse(
            "[object] (RuntimeException(code: 0): Boom at /app/src/Checkout.php:88)\n[stacktrace]\n#0 /app/src/Kernel.php(12): App\Kernel->boot()\n#1 {main}\n",
        );

        $copy = unserialize(serialize($exception));

        self::assertInstanceOf(LoggedException::class, $copy);
        self::assertNotNull($exception);
        self::assertEquals($exception->frames(), $copy->frames());
    }

    #[Test]
    public function stripsTheIndentOfIndentedStacktraces(): void
    {
        $exception = (new ExceptionText())->parse(
            "[object] (RuntimeException(code: 0): Boom at /app/src/Checkout.php:88)\n    [stacktrace]\n    #0 /app/src/Kernel.php(12): run()\n    #1 {main}\n",
        );

        self::assertNotNull($exception);
        self::assertInstanceOf(PhpStacktrace::class, $exception->stacktrace);
        self::assertSame("#0 /app/src/Kernel.php(12): run()\n#1 {main}", $exception->stacktrace->text);
        self::assertCount(2, $exception->frames());
        self::assertSame('run', $exception->frames()[0]->function);
    }

    #[Test]
    public function parsesThePreviousExceptionChain(): void
    {
        $exception = (new ExceptionText())->parse(implode("\n", [
            '[object] (RuntimeException(code: 0): Outer at /app/src/A.php:1)',
            '[stacktrace]',
            '#0 {main}',
            '',
            '[previous exception] [object] (LogicException(code: 3): Middle at /app/src/B.php:2)',
            '[stacktrace]',
            '#0 /app/src/B.php(9): b()',
            '#1 {main}',
            '',
            '[previous exception] [object] (PDOException(code: HY000): Inner at /app/src/C.php:3)',
            '',
        ]));

        self::assertNotNull($exception);
        self::assertSame('Outer', $exception->message);
        self::assertCount(1, $exception->frames());
        self::assertNotNull($exception->previous);
        self::assertSame('LogicException', $exception->previous->type);
        self::assertCount(2, $exception->previous->frames());
        self::assertNotNull($exception->previous->previous);
        self::assertSame('HY000', $exception->previous->previous->code);
        self::assertSame('Inner', $exception->previous->previous->message);
        self::assertNull($exception->previous->previous->previous);
    }

    #[Test]
    public function parsesTheCommaSeparatedChainOfMonolog1(): void
    {
        $exception = (new ExceptionText())->parse(
            "[object] (RuntimeException(code: 42): Outer, with a comma at /app/src/A.php:14, LogicException(code: 0): Middle:\nline at /app/src/B.php:20, PDOException(code: HY000): Inner at /app/src/C.php:3)",
        );

        self::assertNotNull($exception);
        self::assertSame('Outer, with a comma', $exception->message);
        self::assertSame(14, $exception->location->line);
        $middle = $exception->previous;
        self::assertNotNull($middle);
        self::assertSame('LogicException', $middle->type);
        self::assertSame("Middle:\nline", $middle->message);
        self::assertSame('/app/src/B.php', $middle->location->file);
        $inner = $middle->previous;
        self::assertNotNull($inner);
        self::assertSame('HY000', $inner->code);
        self::assertSame(3, $inner->location->line);
    }

    #[Test]
    public function marksTheChainTruncatedWhenMonologStoppedNormalizing(): void
    {
        $exception = (new ExceptionText())->parse(implode("\n", [
            '[object] (RuntimeException(code: 0): Outer at /app/src/A.php:1)',
            '[previous exception] [object] (LogicException(code: 0): Inner at /app/src/B.php:2)',
            '[previous exception] Over 9 levels deep, aborting normalization',
        ]));

        self::assertNotNull($exception);
        self::assertFalse($exception->truncated);
        self::assertNotNull($exception->previous);
        self::assertTrue($exception->previous->truncated);
        self::assertNull($exception->previous->previous);
    }

    #[Test]
    public function ignoresTheDepthGuardAnywhereButAtTheEndOfTheChain(): void
    {
        $exception = (new ExceptionText())->parse(implode("\n", [
            '[object] (RuntimeException(code: 0): Outer at /app/src/A.php:1)',
            '[previous exception] Over 9 levels deep, aborting normalization',
            '[previous exception] [object] (LogicException(code: 0): Inner at /app/src/B.php:2)',
        ]));

        self::assertNotNull($exception);
        self::assertFalse($exception->truncated);
        self::assertSame('LogicException', $exception->previous?->type);
        self::assertFalse($exception->previous->truncated);
    }

    #[Test]
    public function keepsAllFramesInAppWhenEveryFrameIsVendor(): void
    {
        $exception = (new ExceptionText())->parse(
            "[object] (RuntimeException(code: 0): Boom at /app/vendor/a/A.php:1)\n[stacktrace]\n#0 /app/vendor/a/A.php(3): a()\n#1 /app/vendor/b/B.php(4): b()\n#2 {main}\n",
        );

        self::assertNotNull($exception);
        self::assertTrue($exception->frames()[0]->inApp);
        self::assertTrue($exception->frames()[1]->inApp);
    }

    #[Test]
    public function resolvesFramesFromEvaluatedCodeToTheEvaluatingFile(): void
    {
        $exception = (new ExceptionText())->parse(
            "[object] (RuntimeException(code: 0): Boom at /app/src/A.php:1)\n[stacktrace]\n#0 /app/src/Template.php(12) : eval()'d code(3): render()\n#1 {main}\n",
        );

        self::assertNotNull($exception);
        self::assertEquals(new Location('/app/src/Template.php', 12), $exception->frames()[0]->location);
        self::assertSame('render', $exception->frames()[0]->function);
    }

    #[Test]
    public function returnsNullForTextThatIsNoMonologException(): void
    {
        self::assertNull((new ExceptionText())->parse('just a string'));
    }
}
