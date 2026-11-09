<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Entry;

final readonly class Warning
{
    public function __construct(
        public WarningCode $code,
        public string $message,
    ) {
    }
}
