<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests;

use Hyphaeworks\Demonolog\Demonolog;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogFileTest extends TestCase
{
    #[Test]
    public function tailReturnsTheLastEntriesAcrossManyChunks(): void
    {
        $log = '';
        for ($number = 1; $number <= 3000; ++$number) {
            $log .= sprintf("[2026-11-10 10:00:00] app.INFO: entry %d [] []\n", $number);
        }
        $log .= "[2026-11-10 10:00:01] app.ERROR: failed {\"exception\":\"[object] (E(code: 0): x at /a.php:1)\n[stacktrace]\n#0 {main}\n\"} []\n";

        $entries = (new Demonolog())->fromString($log)->tail(2000);

        self::assertCount(2000, $entries);
        self::assertSame('entry 1002', $entries[0]->message);
        self::assertSame('failed', $entries[1999]->message);
        self::assertSame('E', $entries[1999]->exception?->type);
        self::assertSame(strpos($log, '[2026-11-10 10:00:00] app.INFO: entry 1002 '), $entries[0]->offset);
    }

    #[Test]
    public function aLogLineInsideInlineContextSplitsTheRecordButKeepsEveryLine(): void
    {
        $log = "[2026-11-10 10:00:00] app.ERROR: command failed {\"output\":\"\n[2026-11-10 09:59:59] child.ERROR: inner failure\n\"}\n[2026-11-10 10:00:01] app.INFO: next [] []\n";

        $entries = iterator_to_array((new Demonolog())->fromString($log)->entries(), false);

        self::assertSame(['app', 'child', 'app'], array_map(static fn (Entry $entry): ?string => $entry->channel, $entries));
        self::assertNotSame([], $entries[1]->warnings);
        self::assertSame($log, implode('', array_map(static fn (Entry $entry): string => $entry->raw . "\n", $entries)));
    }

    #[Test]
    public function aLineCutAtTheRecordCapComesWithAWarning(): void
    {
        $log = '[2026-11-10 10:00:00] app.INFO: ' . str_repeat('x', 1_200_000) . " [] []\n[2026-11-10 10:00:01] app.INFO: next [] []\n";

        $entries = iterator_to_array((new Demonolog())->fromString($log)->entries(), false);

        self::assertSame([WarningCode::Truncated], array_map(static fn (Warning $warning): WarningCode => $warning->code, $entries[0]->warnings));
        self::assertSame([], $entries[1]->warnings);
    }

    #[Test]
    public function tailFindsTheRecordAfterAVeryLongLine(): void
    {
        $log = '[2026-11-10 10:00:00] app.INFO: ' . str_repeat('x', 100000) . " [] []\n[2026-11-10 10:00:01] app.INFO: last [] []\n";

        $entries = (new Demonolog())->fromString($log)->tail(1);

        self::assertSame('last', $entries[0]->message);
    }

    #[Test]
    public function tailReturnsEverythingWhenTheFileIsShorter(): void
    {
        $entries = (new Demonolog())->fromString("[2026-11-10 10:00:00] app.INFO: one [] []\n[2026-11-10 10:00:01] app.INFO: two [] []")->tail(5);

        self::assertSame(['one', 'two'], array_map(static fn (Entry $entry): string => $entry->message, $entries));
    }

    #[Test]
    public function tailOfNoEntriesIsEmpty(): void
    {
        $entries = (new Demonolog())->fromString("[2026-11-10 10:00:00] app.INFO: one [] []\n")->tail(0);

        self::assertSame([], $entries);
    }

    #[Test]
    public function entryAtParsesTheRecordThatStartsAtTheOffset(): void
    {
        $log = (new Demonolog())->fromString("[2026-11-10 10:00:00] app.INFO: one [] []\n[2026-11-10 10:00:01] app.ERROR: two {\"exception\":\"[object] (E(code: 0): x at /a.php:1)\n[stacktrace]\n#0 {main}\n\"} []\n[2026-11-10 10:00:02] app.INFO: three [] []\n");

        $entry = $log->entryAt(42);

        self::assertNotNull($entry);
        self::assertSame('two', $entry->message);
        self::assertSame('E', $entry->exception?->type);
        self::assertNull($log->entryAt(1000));
    }
}
