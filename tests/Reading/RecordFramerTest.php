<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Reading;

use Hyphaeworks\Demonolog\Reading\RawRecord;
use Hyphaeworks\Demonolog\Reading\RecordFramer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordFramerTest extends TestCase
{
    #[Test]
    public function appendsContinuationLinesToTheirRecord(): void
    {
        $records = $this->frame(["[a] first\n[b] sec", "ond\n#0 trace\n#1 {main}\n[c] th", "ird\n"]);

        self::assertEquals(
            [new RawRecord(0, '[a] first'), new RawRecord(10, "[b] second\n#0 trace\n#1 {main}"), new RawRecord(40, '[c] third')],
            $records,
        );
    }

    #[Test]
    public function dropsCarriageReturnsButCountsTheirBytes(): void
    {
        self::assertEquals(
            [new RawRecord(0, "[a] first\nmore"), new RawRecord(17, '[b] second')],
            $this->frame(["[a] first\r\nmore\r\n[b] second\r\n"]),
        );
    }

    #[Test]
    public function keepsLinesBeforeTheFirstHeaderAsOneRecord(): void
    {
        self::assertEquals(
            [new RawRecord(0, "garbage\nmore garbage"), new RawRecord(21, '[a] first')],
            $this->frame(["garbage\nmore garbage\n[a] first"]),
        );
    }

    #[Test]
    public function holdsBackARecordThatIsStillBeingWritten(): void
    {
        $framer = new RecordFramer(self::startsRecord(...));

        $records = $framer->records(["[a] first\n[b] second\n#0 tra"], 100, holdPartialTail: true);

        self::assertEquals([new RawRecord(100, '[a] first')], iterator_to_array($records, false));
        self::assertSame(110, $records->getReturn());
    }

    #[Test]
    public function emitsTheLastTerminatedRecordWhenHoldingTheTail(): void
    {
        $framer = new RecordFramer(self::startsRecord(...));

        $records = $framer->records(["[a] first\n[b] second\n"], 0, holdPartialTail: true);

        self::assertEquals([new RawRecord(0, '[a] first'), new RawRecord(10, '[b] second')], iterator_to_array($records, false));
        self::assertSame(21, $records->getReturn());
    }

    #[Test]
    public function forcesABoundaryWhenARecordOutgrowsTheCap(): void
    {
        $framer = new RecordFramer(self::startsRecord(...), maximumRecordBytes: 12);

        self::assertEquals(
            [new RawRecord(0, "[a] 1\nline"), new RawRecord(11, "line\nline"), new RawRecord(21, 'line')],
            iterator_to_array($framer->records(["[a] 1\nline\nline\nline\nline\n"]), false),
        );
    }

    #[Test]
    public function cutsALineLongerThanTheCapAndSkipsTheRest(): void
    {
        $framer = new RecordFramer(self::startsRecord(...), maximumRecordBytes: 12);
        $chunks = str_split('[a] ' . str_repeat('x', 30) . "\n[b] next\n[c] " . str_repeat('y', 20), 5);

        $records = $framer->records($chunks);

        self::assertEquals(
            [new RawRecord(0, '[a] xxxxxxxx', truncated: true), new RawRecord(35, '[b] next'), new RawRecord(44, '[c] yyyyyyyy', truncated: true)],
            iterator_to_array($records, false),
        );
        self::assertSame(68, $records->getReturn());
    }

    #[Test]
    public function framesARecordOfManyLinesInLinearTime(): void
    {
        $text = '[a] start' . str_repeat("\nx", 350000) . "\n";

        $started = hrtime(true);
        $records = $this->frame(str_split($text, 65536));

        self::assertCount(1, $records);
        self::assertSame(strlen($text) - 1, strlen($records[0]->text));
        self::assertLessThan(500_000_000, hrtime(true) - $started);
    }

    /**
     * @param list<string> $chunks
     *
     * @return list<RawRecord>
     */
    private function frame(array $chunks): array
    {
        return iterator_to_array((new RecordFramer(self::startsRecord(...)))->records($chunks), false);
    }

    private static function startsRecord(string $line): bool
    {
        return str_starts_with($line, '[');
    }
}
