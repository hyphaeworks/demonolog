<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Following;

use Hyphaeworks\Demonolog\Demonolog;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Following\Update;
use Hyphaeworks\Demonolog\Tests\EmptyChannelFormat;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FollowerTest extends TestCase
{
    private string $path;

    #[Test]
    public function yieldsTheRecordsAppendedSinceThePreviousPoll(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow();

        self::assertSame([], self::messages($follower->poll()));

        file_put_contents($this->path, "[2026-11-10 10:00:01] app.INFO: first [] []\n[2026-11-10 10:00:02] app.INFO: second [] []\n", FILE_APPEND);
        $update = $follower->poll();

        self::assertSame(['first', 'second'], self::messages($update));
        self::assertFalse($update->restarted);
        self::assertSame([], self::messages($follower->poll()));
    }

    #[Test]
    public function returnsARecordItsFormatCannotTurnIntoAnEntryWithAWarning(): void
    {
        $follower = (new Demonolog())->open($this->path, new EmptyChannelFormat())->follow();

        file_put_contents($this->path, "empty channel\ngood\n", FILE_APPEND);
        $update = $follower->poll();

        self::assertSame(['empty channel', 'good'], self::messages($update));
        self::assertSame(WarningCode::Invalid, $update->entries[0]->warnings[0]->code ?? null);
    }

    #[Test]
    public function cannotStartAtANegativeOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset -1 is negative');

        (new Demonolog())->open($this->path)->follow(-1);
    }

    #[Test]
    public function warnsAboutALineCutAtTheRecordCap(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow();

        file_put_contents($this->path, '[2026-11-10 10:00:01] app.INFO: ' . str_repeat('x', 1_200_000) . " [] []\n", FILE_APPEND);

        self::assertSame(WarningCode::Truncated, $follower->poll()->entries[0]->warnings[0]->code ?? null);
    }

    #[Test]
    public function startsAtTheGivenOffset(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow(fromOffset: 0);

        self::assertSame(['before'], self::messages($follower->poll()));
    }

    #[Test]
    public function holdsBackARecordThatIsStillBeingWritten(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow();

        file_put_contents($this->path, "[2026-11-10 10:00:01] app.INFO: first [] []\n[2026-11-10 10:00:02] app.ERROR: second {\"exception\":\"[object] (E(code: 0): x at /a.php:1)\n[stacktrace]", FILE_APPEND);
        self::assertSame(['first'], self::messages($follower->poll()));

        file_put_contents($this->path, "\n#0 {main}\n\"} \n", FILE_APPEND);
        $entries = $follower->poll()->entries;

        self::assertSame(['second'], array_map(static fn (Entry $entry): string => $entry->message, $entries));
        self::assertSame('E', $entries[0]->exception?->type);
    }

    #[Test]
    public function restartsFromTheBeginningWhenTheFileIsTruncated(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow();
        file_put_contents($this->path, "[2026-11-10 10:00:01] app.INFO: first [] []\n", FILE_APPEND);
        $follower->poll();

        file_put_contents($this->path, "[2026-11-10 11:00:00] app.INFO: fresh [] []\n");
        $update = $follower->poll();

        self::assertTrue($update->restarted);
        self::assertSame(['fresh'], self::messages($update));
        self::assertSame(0, $update->entries[0]->offset);
    }

    #[Test]
    public function restartsWithTheNewFileWhenTheFileIsRotated(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow();

        rename($this->path, $this->path . '.1');
        file_put_contents($this->path, "[2026-11-10 12:00:00] app.INFO: rotated [] []\n");
        $update = $follower->poll();

        self::assertTrue($update->restarted);
        self::assertSame(['rotated'], self::messages($update));
        self::assertFalse($follower->poll()->restarted);
    }

    #[Test]
    public function readsTheRestOfTheRotatedFileBeforeTheNewOne(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow();

        file_put_contents($this->path, "[2026-11-10 11:59:59] app.INFO: last [] []\n[2026-11-10 11:59:59] app.INFO: cut off [] []", FILE_APPEND);
        rename($this->path, $this->path . '.1');
        file_put_contents($this->path, "[2026-11-10 12:00:00] app.INFO: rotated [] []\n");
        $update = $follower->poll();

        self::assertSame(['last', 'cut off'], array_map(static fn (Entry $entry): string => $entry->message, $update->fromPreviousFile));
        self::assertTrue($update->restarted);
        self::assertSame(['rotated'], self::messages($update));
    }

    #[Test]
    public function noticesATruncatedFileThatGrewPastTheOldOffset(): void
    {
        $follower = (new Demonolog())->open($this->path)->follow();

        file_put_contents($this->path, "[2026-11-10 11:00:00] app.INFO: fresh [] []\n[2026-11-10 11:00:01] app.INFO: and longer than before [] []\n");
        $update = $follower->poll();

        self::assertTrue($update->restarted);
        self::assertSame([], $update->fromPreviousFile);
        self::assertSame(['fresh', 'and longer than before'], self::messages($update));
    }

    #[Test]
    public function pollingDoesNotDisturbEntriesThatAreStillBeingRead(): void
    {
        file_put_contents($this->path, str_repeat("[2026-11-10 10:00:01] app.INFO: existing [] []\n", 3000));
        $log = (new Demonolog())->open($this->path);
        $follower = $log->follow();
        $entries = $log->entries();

        $count = 0;
        foreach ($entries as $entry) {
            if (++$count === 1) {
                file_put_contents($this->path, "[2026-11-10 10:00:02] app.INFO: appended [] []\n", FILE_APPEND);
                self::assertSame(['appended'], self::messages($follower->poll()));
            }
        }

        self::assertSame(3001, $count);
    }

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'demonolog');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file');
        }
        $this->path = $path;
        file_put_contents($this->path, "[2026-11-10 10:00:00] app.INFO: before [] []\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.1'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function messages(Update $update): array
    {
        return array_map(static fn (Entry $entry): string => $entry->message, $update->entries);
    }
}
