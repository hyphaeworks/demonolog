<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

use InvalidArgumentException;

final readonly class RawRecord
{
    public function __construct(
        public int $offset,
        public string $text,
    ) {
        if ($offset < 0) {
            throw new InvalidArgumentException(sprintf('Offset %d is negative', $offset));
        }
    }
}
