<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

use InvalidArgumentException;

final readonly class RawRecord
{
    /**
     * @param bool $truncated a line of the record was longer than the cap, so its end is missing from the text
     */
    public function __construct(
        public int $offset,
        public string $text,
        public bool $truncated = false,
    ) {
        if ($offset < 0) {
            throw new InvalidArgumentException(sprintf('Offset %d is negative', $offset));
        }
    }
}
