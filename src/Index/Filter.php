<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use Hyphaeworks\Demonolog\Entry\Level;

final readonly class Filter
{
    /**
     * @param ?Level       $minimumLevel an entry with an unknown level is left out
     * @param list<string> $channels     empty keeps every channel
     * @param string       $text         searched in the raw record, ignoring case
     */
    public function __construct(
        public ?Level $minimumLevel = null,
        public array $channels = [],
        public string $text = '',
    ) {
    }
}
