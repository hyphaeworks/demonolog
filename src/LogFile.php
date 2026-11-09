<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog;

use Generator;
use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Reading\ByteSource;
use Hyphaeworks\Demonolog\Reading\RecordFramer;

final readonly class LogFile
{
    private RecordFramer $framer;

    public function __construct(
        private ByteSource $source,
        private Format $format,
    ) {
        $this->framer = new RecordFramer($format->startsRecord(...));
    }

    /**
     * @return Generator<int, Entry>
     */
    public function entries(int $fromOffset = 0): Generator
    {
        foreach ($this->framer->records($this->source->chunks($fromOffset), $fromOffset) as $record) {
            yield $this->format->parse($record);
        }
    }
}
