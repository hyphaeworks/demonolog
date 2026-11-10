<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format;

use Hyphaeworks\Demonolog\Entry\Level;

final readonly class Header
{
    public function __construct(
        public ?Level $level,
        public string $channel,
    ) {
    }
}
