<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format;

use Hyphaeworks\Demonolog\Entry\Level;
use InvalidArgumentException;

final readonly class Header
{
    public function __construct(
        public ?Level $level,
        public ?string $channel,
    ) {
        if ($channel === '') {
            throw new InvalidArgumentException('A channel has a name, or is null');
        }
    }
}
