<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Format\Json;

use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Format\Json\JsonFormat;
use Hyphaeworks\Demonolog\LoggedException\Frame;
use Hyphaeworks\Demonolog\LoggedException\Location;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonFormatTest extends TestCase
{
    #[Test]
    public function parsesAJsonFormatterRecord(): void
    {
        $text = '{"message":"Matched route \"app_home\".","context":{"route":"app_home"},"level":200,"level_name":"INFO","channel":"request","datetime":"2026-11-09T10:15:30.123456+01:00","extra":{}}';

        $entry = (new JsonFormat())->parse(new RawRecord(64, $text));

        self::assertSame(64, $entry->offset);
        self::assertSame($text, $entry->raw);
        self::assertSame('2026-11-09T10:15:30.123456+01:00', $entry->timestamp?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('request', $entry->channel);
        self::assertSame(Level::Info, $entry->level);
        self::assertSame('INFO', $entry->levelName);
        self::assertSame('Matched route "app_home".', $entry->message);
        self::assertSame(['route' => 'app_home'], $entry->context);
        self::assertSame([], $entry->extra);
        self::assertNull($entry->exception);
        self::assertSame([], $entry->warnings);
    }

    #[Test]
    public function anExceptionWithoutAFileHasNoLocation(): void
    {
        $entry = (new JsonFormat())->parse(new RawRecord(0, '{"message":"Failed","context":{"exception":{"class":"RuntimeException","message":"x","code":0,"trace":["","/app/src/A.php:3"]}},"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}'));

        self::assertNull($entry->exception?->location);
        self::assertEquals([Frame::at(0, new Location('/app/src/A.php', 3), true)], $entry->exception?->frames());
    }

    #[Test]
    public function theLevelNameWinsOverAContradictingNumber(): void
    {
        $entry = (new JsonFormat())->parse(new RawRecord(0, '{"message":"Hello","context":{},"level":400,"level_name":"INFO","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}'));

        self::assertSame(Level::Info, $entry->level);
    }

    #[Test]
    public function takesTheLevelFromTheNumberWhenTheNameIsUnknown(): void
    {
        $entry = (new JsonFormat())->parse(new RawRecord(0, '{"message":"Hello","context":{},"level":400,"level_name":"FATAL","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}'));

        self::assertSame([Level::Error, 'FATAL'], [$entry->level, $entry->levelName]);
    }

    #[Test]
    public function anEmptyChannelIsNoChannel(): void
    {
        $entry = (new JsonFormat())->parse(new RawRecord(0, '{"message":"Hello","context":{},"level":200,"level_name":"INFO","channel":"","datetime":"2026-11-09T10:15:30+00:00","extra":{}}'));

        self::assertNull($entry->channel);
    }

    #[Test]
    public function readsTheDatetimeObjectOfMonolog1(): void
    {
        $entry = (new JsonFormat())->parse(new RawRecord(0, '{"message":"Hello","context":{},"level":200,"level_name":"INFO","channel":"app","datetime":{"date":"2026-11-09 21:17:47.799318","timezone_type":3,"timezone":"Europe/Vienna"},"extra":[]}'));

        self::assertSame('2026-11-09T21:17:47.799318+01:00', $entry->timestamp?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame([], $entry->warnings);
    }

    #[Test]
    public function warnsAboutAMissingDatetime(): void
    {
        $entry = (new JsonFormat())->parse(new RawRecord(0, '{"message":"Hello","level_name":"INFO"}'));

        self::assertNull($entry->timestamp);
        self::assertSame(WarningCode::Timestamp, $entry->warnings[0]->code ?? null);
    }

    #[Test]
    public function startsARecordOnEveryJsonObjectLine(): void
    {
        $format = new JsonFormat();

        self::assertTrue($format->startsRecord('{"message":"Hello"}'));
        self::assertFalse($format->startsRecord('[2026-11-09 10:15:30] app.INFO: Hello [] []'));
    }

    #[Test]
    public function detectsJsonFormatterRecordsInASample(): void
    {
        $format = new JsonFormat();

        self::assertTrue($format->detect("\n" . '{"message":"Hello","context":{},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}' . "\n" . '{"mess'));
        self::assertFalse($format->detect('{"some":"other json"}'));
        self::assertFalse($format->detect('[2026-11-09 10:15:30] app.INFO: Hello [] []'));
    }

    #[Test]
    public function mapsTheNormalizedExceptionArray(): void
    {
        $text = '{"message":"Boom","context":{"exception":{"class":"RuntimeException","message":"Boom","code":0,"file":"/app/src/Checkout.php:88",'
            . '"trace":["/app/vendor/symfony/http-kernel/HttpKernel.php:183","/app/public/index.php:5"],'
            . '"previous":{"class":"PDOException","message":"Gone","code":2006,"file":"/app/src/Db.php:9"}}},'
            . '"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-11-09T10:15:30.123456+00:00","extra":{}}';

        $exception = (new JsonFormat())->parse(new RawRecord(0, $text))->exception;

        self::assertNotNull($exception);
        self::assertSame('RuntimeException', $exception->type);
        self::assertSame('Boom', $exception->message);
        self::assertEquals(new Location('/app/src/Checkout.php', 88), $exception->location);
        self::assertEquals(
            [
                Frame::at(0, new Location('/app/vendor/symfony/http-kernel/HttpKernel.php', 183), false),
                Frame::at(1, new Location('/app/public/index.php', 5), true),
            ],
            $exception->frames(),
        );
        self::assertNotNull($exception->previous);
        self::assertSame('PDOException', $exception->previous->type);
        self::assertSame(2006, $exception->previous->code);
        self::assertSame([], $exception->previous->frames());
    }

    #[Test]
    public function turnsALineThatIsNoJsonObjectIntoAnUnparsedEntry(): void
    {
        $entry = (new JsonFormat())->parse(new RawRecord(0, 'PHP Fatal error: out of memory'));

        self::assertSame('PHP Fatal error: out of memory', $entry->message);
        self::assertSame(WarningCode::Unparsed, $entry->warnings[0]->code ?? null);
    }
}
