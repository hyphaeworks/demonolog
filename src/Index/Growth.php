<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Index;

use InvalidArgumentException;

final readonly class Growth
{
    /**
     * @param bool $restarted    the file was replaced or rewritten, so the index started over
     * @param int  $indexedBytes where the next step starts; equal to fileBytes at the end
     * @param int  $fileBytes    the size of the file when this step started
     */
    public function __construct(
        public bool $restarted,
        public int $indexedBytes,
        public int $fileBytes,
    ) {
        if ($indexedBytes < 0 || $indexedBytes > $fileBytes) {
            throw new InvalidArgumentException(sprintf('Indexed %d of %d bytes', $indexedBytes, $fileBytes));
        }
    }

    public function atEnd(): bool
    {
        return $this->indexedBytes === $this->fileBytes;
    }
}
