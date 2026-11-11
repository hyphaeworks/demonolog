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
use OutOfRangeException;
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
    public function readsTheEntryAtAPosition(): void
    {
        $errors = $this->index(self::LOG)->filter(new Filter(minimumLevel: Level::Error));

        $entry = $errors->entryAt(0);

        self::assertSame('payment failed', $entry?->message);
        self::assertSame('E', $entry->exception?->type);
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
    public function aNewFilterReplacesThePreviousOne(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.ERROR: one [] []\n");
        $index = (new Demonolog())->open($path)->index();
        $index->grow();
        $errors = $index->filter(new Filter(minimumLevel: Level::Error));

        file_put_contents($path, "[2026-11-10 10:00:01] app.INFO: two [] []\n", FILE_APPEND);
        $errors->grow();
        $everything = $errors->filter(new Filter(minimumLevel: Level::Info));
        file_put_contents($path, "[2026-11-10 10:00:02] app.INFO: three [] []\n", FILE_APPEND);
        $everything->grow();
        unlink($path);

        self::assertSame([0, 43, 85], array_map($everything->offsetAt(...), range(0, count($everything) - 1)));
    }

    #[Test]
    public function listsTheChannelsOfItsFileInAlphabeticalOrder(): void
    {
        self::assertSame(['app', 'doctrine', 'security'], $this->index(self::LOG)->channels());
    }

    #[Test]
    public function aFilteredIndexListsAllChannels(): void
    {
        $errors = $this->index(self::LOG)->filter(new Filter(channels: ['app']));

        self::assertSame(['app', 'doctrine', 'security'], $errors->channels());
    }

    #[Test]
    public function aRecordWithoutAChannelAddsNone(): void
    {
        self::assertSame(['app'], $this->index("not a record\n[2026-11-10 10:00:00] app.INFO: one [] []\n")->channels());
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
        while (!$index->grow(maximumBytes: 120)->atEnd) {
            ++$steps;
        }

        self::assertGreaterThan(2, $steps);
        self::assertSame($this->messages($this->index(self::LOG)), $this->messages($index));
        self::assertSame(['payment failed'], $this->messages($index->filter(new Filter(text: '{main}'))));
    }

    #[Test]
    public function aStepReportsHowFarItGot(): void
    {
        $log = (new Demonolog())->fromString(self::LOG);
        $index = $log->index();

        $growth = $index->grow(maximumBytes: 120);

        $offsets = array_map(static fn (Entry $entry): int => $entry->offset, iterator_to_array($log->entries(), false));
        self::assertFalse($growth->atEnd);
        self::assertSame($offsets[count($index)], $growth->indexedBytes);
        self::assertSame(strlen(self::LOG), $growth->fileBytes);
    }

    #[Test]
    public function theLastStepReportsTheWholeFile(): void
    {
        $log = rtrim(self::LOG, "\n");
        $index = (new Demonolog())->fromString($log)->index();

        do {
            $growth = $index->grow(maximumBytes: 120);
        } while (!$growth->atEnd);

        self::assertSame([strlen($log), strlen($log)], [$growth->indexedBytes, $growth->fileBytes]);
    }

    #[Test]
    public function aFilteredIndexReportsTheBytesItRead(): void
    {
        $log = (new Demonolog())->fromString(self::LOG);
        $all = $log->index();
        $errors = $log->index()->filter(new Filter(minimumLevel: Level::Error));

        $growth = $all->grow(maximumBytes: 120);
        $filteredGrowth = $errors->grow(maximumBytes: 120);

        self::assertSame([$growth->indexedBytes, $growth->fileBytes], [$filteredGrowth->indexedBytes, $filteredGrowth->fileBytes]);
        self::assertGreaterThan(0, $filteredGrowth->indexedBytes);
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
    public function aLineAppendedWithoutAHeaderBelongsToTheLastEntry(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.INFO: one [] []\n[2026-11-10 10:00:01] app.INFO: two [] []\n");
        $log = (new Demonolog())->open($path);
        $index = $log->index();
        $index->grow();
        $needles = $index->filter(new Filter(text: 'needle'));

        file_put_contents($path, "needle\n", FILE_APPEND);
        $index->grow();
        $needles->grow();
        $entries = iterator_to_array($log->entries(), false);
        unlink($path);

        self::assertSame(array_map(static fn (Entry $entry): int => $entry->offset, $entries), array_map($index->offsetAt(...), range(0, count($index) - 1)));
        self::assertSame([42], array_map($needles->offsetAt(...), range(0, count($needles) - 1)));
    }

    #[Test]
    public function readsARotatedFileFromTheStart(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.INFO: one [] []\n[2026-11-10 10:00:01] app.INFO: two [] []\n");
        $index = (new Demonolog())->open($path)->index();
        $first = $index->grow();

        rename($path, $path . '.1');
        file_put_contents($path, "[2026-11-10 10:00:02] app.INFO: rotated [] []\n");
        $growth = $index->grow();
        $entry = $index->entryAt(0);
        unlink($path);
        unlink($path . '.1');

        self::assertFalse($first->restarted);
        self::assertTrue($growth->restarted);
        self::assertCount(1, $index);
        self::assertSame('rotated', $entry?->message);
    }

    #[Test]
    public function aRotatedFileListsOnlyItsOwnChannels(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] old.INFO: one [] []\n");
        $index = (new Demonolog())->open($path)->index();
        $index->grow();

        rename($path, $path . '.1');
        file_put_contents($path, "[2026-11-10 10:00:01] new.INFO: two [] []\n");
        $index->grow();
        unlink($path);
        unlink($path . '.1');

        self::assertSame(['new'], $index->channels());
    }

    #[Test]
    public function aFilteredIndexKeepsItsFilterAfterARotation(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.ERROR: one [] []\n");
        $index = (new Demonolog())->open($path)->index();
        $index->grow();
        $errors = $index->filter(new Filter(minimumLevel: Level::Error));

        rename($path, $path . '.1');
        file_put_contents($path, "[2026-11-10 10:00:01] app.INFO: two [] []\n[2026-11-10 10:00:02] app.ERROR: three [] []\n");
        $growth = $errors->grow();
        $entry = $errors->entryAt(0);
        unlink($path);
        unlink($path . '.1');

        self::assertTrue($growth->restarted);
        self::assertCount(1, $errors);
        self::assertSame('three', $entry?->message);
    }

    #[Test]
    public function startsOverWhenTheFileWasRewrittenPastItsOldEnd(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.INFO: one [] []\n");
        $index = (new Demonolog())->open($path)->index();
        $index->grow();

        file_put_contents($path, "[2026-11-10 10:00:01] app.INFO: fresh [] []\n[2026-11-10 10:00:02] app.INFO: again [] []\n");
        $growth = $index->grow();
        $messages = array_map(static fn (int $position): string => $index->entryAt($position)->message ?? '', range(0, count($index) - 1));
        unlink($path);

        self::assertTrue($growth->restarted);
        self::assertSame(['fresh', 'again'], $messages);
    }

    #[Test]
    public function aPositionOutsideTheIndexHasNoOffset(): void
    {
        $index = $this->index(self::LOG);

        $this->expectException(OutOfRangeException::class);
        $this->expectExceptionMessage('Position 4 is outside the index of 4 entries');

        $index->offsetAt(4);
    }

    #[Test]
    public function startsOverWhenTheFileShrank(): void
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'demonolog');
        file_put_contents($path, "[2026-11-10 10:00:00] app.INFO: one [] []\n[2026-11-10 10:00:01] app.ERROR: two [] []\n[2026-11-10 10:00:02] app.INFO: three [] []\n");
        $log = (new Demonolog())->open($path);
        $index = $log->index();
        $index->grow();
        $errors = $index->filter(new Filter(minimumLevel: Level::Error));

        file_put_contents($path, "[2026-11-10 10:00:03] app.ERROR: fresh [] []\n");
        $growth = $index->grow();
        $errors->grow();
        $messages = array_map(static fn (int $offset): string => $log->entryAt($offset)->message ?? '', [$index->offsetAt(0), $errors->offsetAt(0)]);
        unlink($path);

        self::assertTrue($growth->restarted);
        self::assertCount(1, $index);
        self::assertCount(1, $errors);
        self::assertSame(['fresh', 'fresh'], $messages);
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
