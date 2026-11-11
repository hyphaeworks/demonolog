<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests;

use Hyphaeworks\Demonolog\Demonolog;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DemonologTest extends TestCase
{
    #[Test]
    public function readsLineRecordsFromAString(): void
    {
        $log = "[2026-11-09 10:15:30] local.INFO: Started [] []\r\n"
            . "[2026-11-09 10:15:31] local.ERROR: Failed {\"exception\":\"[object] (RuntimeException(code: 0): Failed at /app/src/A.php:3)\r\n"
            . "[stacktrace]\r\n"
            . "#0 {main}\r\n"
            . "\"} \r\n";

        $entries = iterator_to_array((new Demonolog())->fromString($log)->entries(), false);

        self::assertSame(['Started', 'Failed'], array_map(static fn (Entry $entry): string => $entry->message, $entries));
        self::assertSame([0, 49], array_map(static fn (Entry $entry): int => $entry->offset, $entries));
        self::assertSame(Level::Error, $entries[1]->level);
        self::assertSame('RuntimeException', $entries[1]->exception?->type);
        self::assertSame([], $entries[1]->warnings);
    }

    #[Test]
    public function detectsJsonRecords(): void
    {
        $log = '{"message":"Started","context":{},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}' . "\n"
            . '{"message":"Failed","context":{},"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-11-09T10:15:31+00:00","extra":{}}' . "\n";

        $entries = iterator_to_array((new Demonolog())->fromString($log)->entries(), false);

        self::assertSame(['Started', 'Failed'], array_map(static fn (Entry $entry): string => $entry->message, $entries));
        self::assertSame(Level::Error, $entries[1]->level);
    }

    #[Test]
    public function readsJsonRecordsAfterAByteOrderMark(): void
    {
        $log = "\xEF\xBB\xBF" . '{"message":"Started","context":{},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}' . "\n"
            . '{"message":"Failed","context":{},"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-11-09T10:15:31+00:00","extra":{}}' . "\n";

        $entries = iterator_to_array((new Demonolog())->fromString($log)->entries(), false);

        self::assertSame(['Started', 'Failed'], array_map(static fn (Entry $entry): string => $entry->message, $entries));
        self::assertSame([], $entries[0]->warnings);
    }

    #[Test]
    public function detectsJsonRecordsInAFileThatStartsInTheMiddleOfARecord(): void
    {
        $log = '"channel":"app","datetime":"2026-11-09T10:15:29+00:00","extra":{}}' . "\n"
            . '{"message":"Started","context":{},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}' . "\n"
            . '{"message":"Failed","context":{},"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-11-09T10:15:31+00:00","extra":{}}' . "\n";

        $entries = iterator_to_array((new Demonolog())->fromString($log)->entries(), false);

        self::assertSame(['"channel":"app","datetime":"2026-11-09T10:15:29+00:00","extra":{}}', 'Started', 'Failed'], array_map(static fn (Entry $entry): string => $entry->message, $entries));
    }

    #[Test]
    public function detectsJsonRecordsWhenTheFirstRecordIsLongerThanTheSample(): void
    {
        $long = str_repeat('x', 70000);
        $log = '{"message":"' . $long . '","context":{},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}' . "\n"
            . '{"message":"Failed","context":{},"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-11-09T10:15:31+00:00","extra":{}}' . "\n";

        $entries = iterator_to_array((new Demonolog())->fromString($log)->entries(), false);

        self::assertSame([$long, 'Failed'], array_map(static fn (Entry $entry): string => $entry->message, $entries));
    }

    #[Test]
    public function detectsAFormatAddedByAnotherPackageFirst(): void
    {
        $format = new class implements Format {
            public function detect(string $sample): bool
            {
                return str_starts_with($sample, 'Fri, ');
            }

            public function startsRecord(string $line): bool
            {
                return str_starts_with($line, 'Fri, ');
            }

            public function parse(RawRecord $record): Entry
            {
                return new Entry($record->offset, $record->text, null, 'added', Level::Warning, 'WARNING', $record->text);
            }
        };

        $entries = iterator_to_array(
            (new Demonolog($format))->fromString("Fri, 19 Jul 2023 09:45:00 +0100 [WARNING] first\nFri, 19 Jul 2023 09:45:01 +0100 [WARNING] second\n")->entries(),
            false,
        );

        self::assertSame(['added', 'added'], array_map(static fn (Entry $entry): string => $entry->channel, $entries));
    }

    #[Test]
    public function readsAFileFromAnOffset(): void
    {
        $entries = iterator_to_array((new Demonolog())->open(__DIR__ . '/Fixtures/brackets.log')->entries(fromOffset: 57), false);

        self::assertCount(4, $entries);
        self::assertSame(57, $entries[0]->offset);
        self::assertSame(['test' => '}'], $entries[0]->context);
    }

    #[Test]
    public function refusesAPathThatCannotBeRead(): void
    {
        $this->expectException(RuntimeException::class);

        (new Demonolog())->open(__DIR__ . '/Fixtures/missing.log');
    }
}
