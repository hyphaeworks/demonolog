<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Format\Line;

use Hyphaeworks\Demonolog\Format\Line\ContextSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContextSplitterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function messagesWithContextAndExtra(): iterable
    {
        yield 'empty context and extra' => ['User logged in [] []', 'User logged in', [], []];
        yield 'context and extra' => ['User logged in {"id":5} {"ip":"::1"}', 'User logged in', ['id' => 5], ['ip' => '::1']];
        yield 'brackets in the message' => ['Matched route [app_home] {"route":"app_home"} []', 'Matched route [app_home]', ['route' => 'app_home'], []];
        yield 'empty message' => ['{"id":5} []', '', ['id' => 5], []];
        yield 'empty extra skipped' => ['Saved {"id":5} ', 'Saved', ['id' => 5], []];
        yield 'brackets ending the message, empty extra skipped' => ["Slow request\nfor /checkout [POST] {\"id\":5} ", "Slow request\nfor /checkout [POST]", ['id' => 5], []];
        yield 'escaped quote in a string' => ['Said {"text":"a \"} [\" b"} []', 'Said', ['text' => 'a "} [" b'], []];
        yield 'big integer kept as string' => ['Id {"id":12345678901234567890} []', 'Id', ['id' => '12345678901234567890'], []];
    }

    /**
     * @return iterable<string, array{string, string, array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function devdotBracketLines(): iterable
    {
        $expected = [
            ['log', ['message', ']'], []],
            ['log', ['test' => '}'], []],
            ['log', ['test' => '} ['], []],
            ['log', ['test' => ']'], ['foo' => '} {']],
            ['log', ['test' => '['], ['foo' => '] {']],
            ['log', ['test' => '} ['], []],
            ['log', ['message', '] ['], []],
            ['log', ['test' => '} {'], []],
        ];
        $lines = [...self::lines('brackets.log'), ...self::lines('brackets-fail.log')];

        foreach ($lines as $number => $line) {
            yield $line => [substr($line, strlen('[2023-01-31 12:00:00] test.INFO: ')), ...$expected[$number]];
        }
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    #[Test]
    #[DataProvider('messagesWithContextAndExtra')]
    #[DataProvider('devdotBracketLines')]
    public function splitsMessageContextAndExtra(string $rest, string $message, array $context, array $extra): void
    {
        $split = (new ContextSplitter())->split($rest);

        self::assertSame($message, $split['message']);
        self::assertSame($context, $split['context']);
        self::assertSame($extra, $split['extra']);
        self::assertFalse($split['undecodable']);
    }

    #[Test]
    public function repairsRawLineBreaksInsideJsonStrings(): void
    {
        $split = (new ContextSplitter())->split("Failed {\"exception\":\"[object] (E(code: 0): x at /a.php:1)\n[stacktrace]\n#0 {main}\n\"} ");

        self::assertSame('Failed', $split['message']);
        self::assertSame(['exception' => "[object] (E(code: 0): x at /a.php:1)\n[stacktrace]\n#0 {main}\n"], $split['context']);
    }

    #[Test]
    public function requiresASpaceBetweenContextAndExtra(): void
    {
        $split = (new ContextSplitter())->split('Saved {"id":5}x[]');

        self::assertSame('Saved {"id":5}x[]', $split['message']);
        self::assertSame([], $split['context']);
    }

    #[Test]
    public function boundsTheWorkOnAMessageFullOfOpenBrackets(): void
    {
        $rest = 'log' . str_repeat(' [', 50000);

        $started = hrtime(true);
        $split = (new ContextSplitter())->split($rest);

        self::assertSame(rtrim($rest), $split['message']);
        self::assertLessThan(2_000_000_000, hrtime(true) - $started);
    }

    #[Test]
    public function boundsTheWorkOnAMessageFullOfClosedBrackets(): void
    {
        $rest = 'log' . str_repeat(' []', 350000) . ' end';

        $started = hrtime(true);
        $split = (new ContextSplitter())->split($rest);

        self::assertSame($rest, $split['message']);
        self::assertLessThan(2_000_000_000, hrtime(true) - $started);
    }

    #[Test]
    public function keepsAPlainMessageWithoutJson(): void
    {
        $split = (new ContextSplitter())->split('User [5] logged in');

        self::assertSame('User [5] logged in', $split['message']);
        self::assertSame([], $split['context']);
        self::assertFalse($split['undecodable']);
    }

    #[Test]
    public function flagsAJsonTailThatDoesNotDecode(): void
    {
        $split = (new ContextSplitter())->split('Broken {"id":5,} []');

        self::assertSame('Broken {"id":5,} []', $split['message']);
        self::assertSame([], $split['context']);
        self::assertSame([], $split['extra']);
        self::assertTrue($split['undecodable']);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $fixture): array
    {
        $lines = file(__DIR__ . '/../../Fixtures/' . $fixture, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Fixture %s is missing', $fixture));
        }

        return $lines;
    }
}
