<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Following;

use Hyphaeworks\Demonolog\Entry\Entry;
use InvalidArgumentException;

final readonly class Update
{
    /**
     * @param list<Entry> $entries
     * @param bool        $restarted        the file was truncated or rotated, so earlier offsets no longer apply
     * @param list<Entry> $fromPreviousFile the rest of a rotated file, read before it was let go
     */
    public function __construct(
        public array $entries,
        public bool $restarted,
        public array $fromPreviousFile = [],
    ) {
        if (!$restarted && $fromPreviousFile !== []) {
            throw new InvalidArgumentException('Only a restart has entries from a previous file');
        }
    }
}
