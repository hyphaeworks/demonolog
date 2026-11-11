<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog;

use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Format\Json\JsonFormat;
use Hyphaeworks\Demonolog\Format\Line\LineFormat;
use Hyphaeworks\Demonolog\Reading\ByteSource;
use Hyphaeworks\Demonolog\Reading\Lines;

final readonly class Demonolog
{
    private const SAMPLE_BYTES = 65536;

    /**
     * @var list<Format>
     */
    private array $formats;

    public function __construct(Format ...$addedFormats)
    {
        $this->formats = [...array_values($addedFormats), new LineFormat(), new JsonFormat()];
    }

    public function open(string $path, ?Format $format = null): LogFile
    {
        $source = ByteSource::open($path);

        return new LogFile($source, $format ?? $this->detect($source->read(0, self::SAMPLE_BYTES)));
    }

    public function fromString(string $text, ?Format $format = null): LogFile
    {
        $source = ByteSource::fromString($text);

        return new LogFile($source, $format ?? $this->detect($source->read(0, self::SAMPLE_BYTES)));
    }

    private function detect(string $sample): Format
    {
        $sample = Lines::withoutByteOrderMark($sample);
        foreach ($this->formats as $format) {
            if ($format->detect($sample)) {
                return $format;
            }
        }

        return new LineFormat();
    }
}
