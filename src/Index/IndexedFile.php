<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use Countable;

/**
 * @internal
 */
final class IndexedFile implements Countable
{
    // Channel ids have 16 bits. Records without a channel and channels beyond that share this id, which no channel filter matches.
    private const NO_CHANNEL_ID = 0xFFFF;

    private Rows $rows;
    private int $resumeAt = 0;
    // The bytes before resumeAt. If they changed, the file was rewritten.
    private string $fingerprint = '';
    // The last record of the file is read again on the next step. It may still be being written or get lines without a header appended.
    private ?int $openRecordAt = null;

    /**
     * @var array<string, int>
     */
    private array $channelIds = [];

    /**
     * @var list<string>
     */
    private array $channelNames = [];

    public function __construct()
    {
        $this->rows = new Rows();
    }

    public function __clone()
    {
        $this->rows = clone $this->rows;
    }

    public function count(): int
    {
        return $this->rows->count();
    }

    /**
     * @return array{offset: int, length: int, level: int, channel: int}
     */
    public function at(int $position): array
    {
        return $this->rows->at($position);
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = $this->channelNames;
        sort($channels);

        return $channels;
    }

    public function channelName(int $channelId): ?string
    {
        return $this->channelNames[$channelId] ?? null;
    }

    public function resumeAt(): int
    {
        return $this->resumeAt;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function reopenLastRecord(): ?int
    {
        $offset = $this->openRecordAt;
        if ($offset === null) {
            return null;
        }
        $this->rows->removeLastIfAt($offset);
        $this->resumeAt = $offset;
        $this->openRecordAt = null;

        return $offset;
    }

    /**
     * @return array{offset: int, length: int, level: int, channel: int}
     */
    public function add(int $offset, int $length, int $level, ?string $channel): array
    {
        $row = ['offset' => $offset, 'length' => $length, 'level' => $level, 'channel' => $this->channelId($channel)];
        $this->rows->add($row);

        return $row;
    }

    public function stopAt(int $offset, string $fingerprint, ?int $openRecordAt): void
    {
        $this->resumeAt = $offset;
        $this->fingerprint = $fingerprint;
        $this->openRecordAt = $openRecordAt;
    }

    private function channelId(?string $channel): int
    {
        if ($channel === null) {
            return self::NO_CHANNEL_ID;
        }
        if (isset($this->channelIds[$channel])) {
            return $this->channelIds[$channel];
        }
        if (count($this->channelNames) >= self::NO_CHANNEL_ID) {
            return self::NO_CHANNEL_ID;
        }
        $this->channelNames[] = $channel;

        return $this->channelIds[$channel] = count($this->channelNames) - 1;
    }
}
