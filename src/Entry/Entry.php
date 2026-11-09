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
        public string $channel,
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
    }

    public static function unparsed(int $offset, string $raw, Warning $warning): self
    {
        return new self(
            offset: $offset,
            raw: $raw,
            timestamp: null,
            channel: '',
            level: null,
            levelName: '',
            message: $raw,
            warnings: [$warning],
        );
    }
}
