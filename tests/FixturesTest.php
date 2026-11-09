<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests;

use Hyphaeworks\Demonolog\Demonolog;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\LoggedException\LoggedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function fixtures(): iterable
    {
        yield 'Monolog 1 line' => ['monolog1-default.log', 4, true];
        yield 'Monolog 2 line' => ['monolog2-default.log', 4, true];
        yield 'Monolog 3 line' => ['monolog3-default.log', 4, true];
        yield 'Monolog 1 JSON' => ['monolog1-json.log', 2, true];
        yield 'Monolog 2 JSON' => ['monolog2-json.log', 2, true];
        yield 'Monolog 3 JSON' => ['monolog3-json.log', 2, true];
        yield 'Laravel' => ['laravel.log', 5, true];
        yield 'Symfony dev' => ['symfony-dev.log', 5, true];
        yield 'devdot brackets' => ['brackets.log', 5, false];
        yield 'devdot brackets that devdot fails on' => ['brackets-fail.log', 3, false];
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function parsesEveryRecordOfARealLogFile(string $fixture, int $count, bool $logsTheCheckoutException): void
    {
        $entries = iterator_to_array((new Demonolog())->open(__DIR__ . '/Fixtures/' . $fixture)->entries(), false);

        self::assertCount($count, $entries);
        foreach ($entries as $entry) {
            self::assertSame([], $entry->warnings, $entry->raw);
            self::assertNotNull($entry->level, $entry->raw);
            self::assertNotNull($entry->timestamp, $entry->raw);
        }

        if ($logsTheCheckoutException) {
            $chain = self::chain(self::lastException($entries));
            self::assertSame(
                ['RuntimeException', 'LogicException', 'DomainException'],
                array_map(static fn (LoggedException $link): string => $link->type, $chain),
            );
            self::assertSame(
                ['Checkout failed for order order-7 at step [payment]', "Card declined:\nlimit reached", 'Gateway said {"code":"limit"}'],
                array_map(static fn (LoggedException $link): string => $link->message, $chain),
            );
        }
    }

    /**
     * @param list<Entry> $entries
     */
    private static function lastException(array $entries): ?LoggedException
    {
        $exceptions = array_values(array_filter(array_map(static fn (Entry $entry): ?LoggedException => $entry->exception, $entries)));

        return $exceptions[count($exceptions) - 1] ?? null;
    }

    /**
     * @return list<LoggedException>
     */
    private static function chain(?LoggedException $exception): array
    {
        $chain = [];
        for (; $exception !== null; $exception = $exception->previous) {
            $chain[] = $exception;
        }

        return $chain;
    }
}
