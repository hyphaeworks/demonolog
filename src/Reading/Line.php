<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

/**
 * @internal
 */
final readonly class Line
{
    public function __construct(
        public int $offset,
        public string $text,
        public int $end,
        public bool $terminated,
    ) {
    }
}
