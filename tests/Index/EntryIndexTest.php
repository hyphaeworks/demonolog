<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Index;

use Hyphaeworks\Demonolog\Demonolog;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Format\Header;
use Hyphaeworks\Demonolog\Format\ReadsHeaders;
use Hyphaeworks\Demonolog\Index\EntryIndex;
use Hyphaeworks\Demonolog\Index\Filter;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EntryIndexTest extends TestCase
{
    private const LOG = "[2026-11-10 10:00:00] app.DEBUG: booting [] []\n"
        . "[2026-11-10 10:00:01] security.INFO: user logged in [] []\n"
        . "[2026-11-10 10:00:02] app.ERROR: payment failed {\"exception\":\"[object] (E(code: 0): x at /a.php:1)\n[stacktrace]\n#0 {main}\n\"} []\n"
        . "[2026-11-10 10:00:03] doctrine.WARNING: slow query [] []\n";

    #[Test]
    public function indexesTheOffsetOfEveryRecord(): void
    {
        $log = (new Demonolog())->fromString(self::LOG);
        $index = $log->index();

        $index->grow();

        self::assertCount(4, $index);
        self::assertSame(
            array_map(static fn (Entry $entry): int => $entry->offset, iterator_to_array($log->entries(), false)),
            array_map($index->offsetAt(...), range(0, 3)),
        );
    }

    #[Test]
    public function keepsEntriesAtOrAboveAMinimumLevel(): void
    {
        $filtered = $this->index(self::LOG)->filter(new Filter(minimumLevel: Level::Warning));

        self::assertSame(['payment failed', 'slow query'], $this->messages($filtered));
    }

    #[Test]
    public function keepsEntriesOfTheGivenChannels(): void
    {
        $filtered = $this->index(self::LOG)->filter(new Filter(channels: ['security', 'doctrine']));

        self::assertSame(['user logged in', 'slow query'], $this->messages($filtered));
    }

    #[Test]
    public function keepsEntriesWhoseRecordContainsTheTextIgnoringCase(): void
    {
        $filtered = $this->index(self::LOG)->filter(new Filter(text: 'STACKTRACE'));

        self::assertSame(['payment failed'], $this->messages($filtered));
    }

    #[Test]
    public function findsTextOnTheLastLineOfARecordWithCarriageReturns(): void
    {
        $filtered = $this->index("[2026-11-10 10:00:00] app.INFO: other [] []\r\n[2026-11-10 10:00:01] app.ERROR: first\r\nsecond\r\nneedle\r\n")
            ->filter(new Filter(text: 'needle'));

        self::assertCount(1, $filtered);
        self::assertSame(45, $filtered->offsetAt(0));
    }

    #[Test]
    public function combinesTheConditions(): void
    {
        $filtered = $this->index(self::LOG)->filter(new Filter(minimumLevel: Level::Info, channels: ['app'], text: 'failed'));

        self::assertSame(['payment failed'], $this->messages($filtered));
    }

    #[Test]
    public function aFilteredIndexKeepsFilteringWhenItGrows(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.ERROR: one [] []\n");
        $index = (new Demonolog())->open($path)->index();
        $index->grow();
        $errors = $index->filter(new Filter(minimumLevel: Level::Error, channels: ['app', 'mailer']));

        file_put_contents($path, "[2026-11-10 10:00:01] app.INFO: two [] []\n[2026-11-10 10:00:02] mailer.ERROR: three [] []\n", FILE_APPEND);
        $errors->grow();
        unlink($path);

        self::assertSame([0, 85], array_map($errors->offsetAt(...), range(0, count($errors) - 1)));
    }

    #[Test]
    public function channelsBeyondTheIdRangeNeverMatchAnotherChannel(): void
    {
        $log = '';
        for ($channel = 0; $channel <= 65536; ++$channel) {
            $log .= sprintf("[2026-11-10 10:00:00] c%d.INFO: m [] []\n", $channel);
        }

        $filtered = $this->index($log)->filter(new Filter(channels: ['c0']));

        self::assertCount(1, $filtered);
    }

    #[Test]
    public function growsInStepsWithoutCuttingARecord(): void
    {
        $index = (new Demonolog())->fromString(self::LOG)->index();

        $steps = 1;
        while (!$index->grow(maximumBytes: 120)) {
            ++$steps;
        }

        self::assertGreaterThan(2, $steps);
        self::assertSame($this->messages($this->index(self::LOG)), $this->messages($index));
        self::assertSame(['payment failed'], $this->messages($index->filter(new Filter(text: '{main}'))));
    }

    #[Test]
    public function growsWithAFileThatIsStillBeingWritten(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.INFO: one [] []\n[2026-11-10 10:00:01] app.INFO: tw");
        $index = (new Demonolog())->open($path)->index();

        $index->grow();
        $filtered = $index->filter(new Filter(text: 'tw'));
        self::assertCount(2, $index);
        self::assertCount(1, $filtered);

        file_put_contents($path, "o [] []\n[2026-11-10 10:00:02] app.INFO: three [] []\n", FILE_APPEND);
        $index->grow();
        $filtered->grow();
        unlink($path);

        self::assertCount(3, $index);
        self::assertSame(42, $index->offsetAt(1));
        self::assertCount(1, $filtered);
    }

    #[Test]
    public function readsOnlyTheHeaderWhenTheFormatOffersIt(): void
    {
        $format = new class implements Format, ReadsHeaders {
            public function detect(string $sample): bool
            {
                return true;
            }

            public function startsRecord(string $line): bool
            {
                return true;
            }

            public function parse(RawRecord $record): Entry
            {
                throw new RuntimeException('The index must not parse whole records');
            }

            public function header(RawRecord $record): Header
            {
                return new Header(str_starts_with($record->text, 'E') ? Level::Error : Level::Info, 'app');
            }
        };
        $index = (new Demonolog())->fromString("I one\nE two\nI three\n", $format)->index();

        $index->grow();

        self::assertSame(6, $index->filter(new Filter(minimumLevel: Level::Error, channels: ['app']))->offsetAt(0));
    }

    private function index(string $log): EntryIndex
    {
        $index = (new Demonolog())->fromString($log)->index();
        $index->grow();

        return $index;
    }

    /**
     * @return list<string>
     */
    private function messages(EntryIndex $index): array
    {
        $log = (new Demonolog())->fromString(self::LOG);

        return array_map(static fn (int $position): string => $log->entryAt($index->offsetAt($position))->message ?? '', $index->count() === 0 ? [] : range(0, $index->count() - 1));
    }
}
