<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

use InvalidArgumentException;

final readonly class LoggedException
{
    public function __construct(
        public string $type,
        public string $message,
        public int|string $code,
        public ?Location $location,
        public ?Stacktrace $stacktrace = null,
        public ?self $previous = null,
        public bool $truncated = false,
    ) {
        if ($truncated && $previous !== null) {
            throw new InvalidArgumentException('Only the innermost exception of a chain can be truncated');
        }
    }

    /**
     * @return list<Frame>
     */
    public function frames(): array
    {
        return $this->stacktrace?->frames() ?? [];
    }
}
