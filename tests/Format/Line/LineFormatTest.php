<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Format\Line;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Format\Line\LineFormat;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LineFormatTest extends TestCase
{
    #[Test]
    public function parsesASymfonyRecord(): void
    {
        $text = '[2026-11-09T10:15:30.123456+01:00] request.INFO: Matched route "app_home". {"route":"app_home"} {"url":"/"}';

        $entry = (new LineFormat())->parse(new RawRecord(120, $text));

        self::assertSame(120, $entry->offset);
        self::assertSame($text, $entry->raw);
        self::assertSame('2026-11-09T10:15:30.123456+01:00', $entry->timestamp?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('request', $entry->channel);
        self::assertSame(Level::Info, $entry->level);
        self::assertSame('INFO', $entry->levelName);
        self::assertSame('Matched route "app_home".', $entry->message);
        self::assertSame(['route' => 'app_home'], $entry->context);
        self::assertSame(['url' => '/'], $entry->extra);
        self::assertNull($entry->exception);
        self::assertSame([], $entry->warnings);
    }

    #[Test]
    public function recognisesTheLinesThatStartARecord(): void
    {
        $format = new LineFormat();

        self::assertTrue($format->startsRecord('[2026-11-09 10:15:30] local.ERROR: Division by zero {"exception":"[object] (Error(code: 0): x'));
        self::assertTrue($format->startsRecord('[2026-11-09T10:15:30.123456+01:00] my-app.sub.ERR: '));
        self::assertFalse($format->startsRecord('[stacktrace]'));
        self::assertFalse($format->startsRecord('[previous exception] [object] (LogicException(code: 0): Inner at /a.php:2)'));
        self::assertFalse($format->startsRecord('#0 {main}'));
        self::assertFalse($format->startsRecord('{"message":"Hello"}'));
    }

    #[Test]
    public function detectsLineRecordsInASample(): void
    {
        $format = new LineFormat();

        self::assertTrue($format->detect("\n[2026-11-09 10:15:30] app.INFO: Hello [] []\n[2026-11-09 10:15:31] app.INFO: Again [] []\n"));
        self::assertFalse($format->detect('{"message":"Hello","context":{},"level":200,"level_name":"INFO","channel":"app","datetime":"2026-11-09T10:15:30+00:00","extra":{}}'));
        self::assertFalse($format->detect(''));
    }

    #[Test]
    public function parsesTheExceptionOfALaravelRecord(): void
    {
        $text = <<<'LOG'
            [2026-11-09 10:15:30] local.ERROR: Division by zero {"userId":5,"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /var/www/app/Http/Controllers/HomeController.php:12)
            [stacktrace]
            #0 /var/www/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\HomeController->index()
            #1 /var/www/public/index.php(17): Illuminate\\Foundation\\Http\\Kernel->handle(Object(Illuminate\\Http\\Request))
            #2 {main}
            "}
            LOG;

        $entry = (new LineFormat())->parse(new RawRecord(0, $text . ' '));

        self::assertSame('Division by zero', $entry->message);
        self::assertSame(5, $entry->context['userId']);
        self::assertNotNull($entry->exception);
        self::assertSame('DivisionByZeroError', $entry->exception->type);
        self::assertSame('/var/www/app/Http/Controllers/HomeController.php', $entry->exception->location->file);
        self::assertSame('App\Http\Controllers\HomeController', $entry->exception->frames()[0]->class);
        self::assertFalse($entry->exception->frames()[0]->inApp);
        self::assertTrue($entry->exception->frames()[1]->inApp);
        self::assertSame([], $entry->warnings);
    }

    #[Test]
    public function keepsTruncatedAndCustomLevelNames(): void
    {
        $truncated = (new LineFormat())->parse(new RawRecord(0, '[2026-11-09 10:15:30] my-app.sub.ERR: Hello [] []'));
        $custom = (new LineFormat())->parse(new RawRecord(0, '[2026-11-09 10:15:30] app.TRACE: Hello [] []'));

        self::assertSame('my-app.sub', $truncated->channel);
        self::assertSame(Level::Error, $truncated->level);
        self::assertSame('ERR', $truncated->levelName);
        self::assertNull($custom->level);
        self::assertSame('TRACE', $custom->levelName);
    }

    #[Test]
    public function turnsTextThatIsNoRecordIntoAnUnparsedEntry(): void
    {
        $entry = (new LineFormat())->parse(new RawRecord(7, 'PHP Fatal error:  Allowed memory size exhausted'));

        self::assertSame(7, $entry->offset);
        self::assertNull($entry->timestamp);
        self::assertNull($entry->level);
        self::assertSame('', $entry->channel);
        self::assertSame('PHP Fatal error:  Allowed memory size exhausted', $entry->message);
        self::assertSame([WarningCode::Unparsed], self::warningCodes($entry));
    }

    #[Test]
    public function warnsAboutAContextThatDoesNotDecode(): void
    {
        $entry = (new LineFormat())->parse(new RawRecord(0, '[2026-11-09 10:15:30] app.INFO: Broken {"id":5,} []'));

        self::assertSame('Broken {"id":5,} []', $entry->message);
        self::assertSame([WarningCode::ContextJson], self::warningCodes($entry));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function timestamps(): iterable
    {
        yield 'Monolog 2 and 3 default' => ['2026-11-09T10:15:30.123456+01:00', '2026-11-09T10:15:30.123456+01:00'];
        yield 'Monolog without microseconds' => ['2026-11-09T10:15:30+01:00', '2026-11-09T10:15:30.000000+01:00'];
        yield 'Monolog 1 and Laravel, read as UTC' => ['2026-11-09 10:15:30', '2026-11-09T10:15:30.000000+00:00'];
        yield 'space separated with microseconds' => ['2026-11-09 10:15:30.123456', '2026-11-09T10:15:30.123456+00:00'];
        yield 'space separated with zone' => ['2026-11-09 10:15:30+02:00', '2026-11-09T10:15:30.000000+02:00'];
        yield 'RFC 2822' => ['Mon, 09 Nov 2026 10:15:30 +0100', '2026-11-09T10:15:30.000000+01:00'];
        yield 'any other format PHP understands' => ['09.11.2026 10:15', '2026-11-09T10:15:00.000000+00:00'];
    }

    #[Test]
    #[DataProvider('timestamps')]
    public function parsesTimestamps(string $written, string $expected): void
    {
        $entry = (new LineFormat())->parse(new RawRecord(0, '[' . $written . '] app.INFO: Hello [] []'));

        self::assertSame($expected, $entry->timestamp?->format('Y-m-d\TH:i:s.uP'));
        self::assertSame([], $entry->warnings);
    }

    #[Test]
    public function warnsAboutAnUnparseableTimestamp(): void
    {
        $entry = (new LineFormat())->parse(new RawRecord(0, '[not a real date] app.INFO: Hello [] []'));

        self::assertNull($entry->timestamp);
        self::assertSame('Hello', $entry->message);
        self::assertSame([WarningCode::Timestamp], self::warningCodes($entry));
    }

    #[Test]
    public function warnsAboutAnInvalidDateInsteadOfRollingItOver(): void
    {
        $entry = (new LineFormat())->parse(new RawRecord(0, '[2026-02-30 10:15:30] app.INFO: Hello [] []'));

        self::assertNull($entry->timestamp);
        self::assertSame([WarningCode::Timestamp], self::warningCodes($entry));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function records(): iterable
    {
        yield 'symfony' => ['[2026-11-10T10:00:00.123456+01:00] request.CRITICAL: Uncaught {"exception":"[object] (E(code: 0): x at /a.php:1)"} []'];
        yield 'custom level' => ['[2026-11-10 10:00:00] my.app.NOTI: short level name [] []'];
        yield 'no record' => ['PHP Fatal error: out of memory'];
    }

    #[Test]
    #[DataProvider('records')]
    public function readsTheSameHeaderAsAFullParse(string $text): void
    {
        $format = new LineFormat();
        $record = new RawRecord(0, $text);

        $header = $format->header($record);
        $entry = $format->parse($record);

        self::assertSame([$entry->level, $entry->channel], [$header->level, $header->channel]);
    }

    /**
     * @return list<WarningCode>
     */
    private static function warningCodes(Entry $entry): array
    {
        return array_map(static fn (Warning $warning): WarningCode => $warning->code, $entry->warnings);
    }
}
