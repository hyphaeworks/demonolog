<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Entry;

use DateTimeImmutable;
use Hyphaeworks\Demonolog\LoggedException\LoggedException;
use InvalidArgumentException;

final readonly class Entry
{
    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     * @param list<Warning>           $warnings
     */
    public function __construct(
        public int $offset,
        public string $raw,
        public ?DateTimeImmutable $timestamp,
        public ?string $channel,
        public ?Level $level,
        public string $levelName,
        public string $message,
        public array $context = [],
        public array $extra = [],
        public ?LoggedException $exception = null,
        public array $warnings = [],
    ) {
        if ($offset < 0) {
            throw new InvalidArgumentException(sprintf('Offset %d is negative', $offset));
        }
        if ($channel === '') {
            throw new InvalidArgumentException('A channel has a name, or is null');
        }
        $named = Level::fromName($levelName);
        if ($named !== null && $named !== $level) {
            throw new InvalidArgumentException(sprintf('Level %s contradicts the level name "%s"', $level->name ?? 'null', $levelName));
        }
    }

    public static function unparsed(int $offset, string $raw, Warning $warning): self
    {
        return new self(
            offset: $offset,
            raw: $raw,
            timestamp: null,
            channel: null,
            level: null,
            levelName: '',
            message: $raw,
            warnings: [$warning],
        );
    }

    public function withWarning(Warning $warning): self
    {
        return new self(
            $this->offset,
            $this->raw,
            $this->timestamp,
            $this->channel,
            $this->level,
            $this->levelName,
            $this->message,
            $this->context,
            $this->extra,
            $this->exception,
            [...$this->warnings, $warning],
        );
    }
}
