<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

final readonly class Growth
{
    /**
     * @param bool $atEnd        the index reached the end of the file
     * @param bool $restarted    the file was replaced or rewritten, so the index started over
     * @param int  $indexedBytes where the next step starts; equal to fileBytes at the end
     * @param int  $fileBytes    the size of the file when this step started
     */
    public function __construct(
        public bool $atEnd,
        public bool $restarted,
        public int $indexedBytes,
        public int $fileBytes,
    ) {
    }
}
