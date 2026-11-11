<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

use Generator;

/**
 * @internal
 */
final readonly class Lines
{
    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    public function __construct(
        private int $maximumLineBytes,
    ) {
    }

    /**
     * A line longer than the maximum is cut and the rest skipped. A file without newlines cannot fill memory.
     *
     * @param iterable<string> $chunks
     *
     * @return Generator<int, Line>
     */
    public function of(iterable $chunks, int $offset): Generator
    {
        $pending = '';
        $skipped = 0;

        foreach ($chunks as $chunk) {
            if ($skipped > 0) {
                $newline = strpos($chunk, "\n");
                if ($newline === false) {
                    $skipped += strlen($chunk);
                    continue;
                }
                $skipped += $newline;
                $chunk = substr($chunk, $newline);
            }

            $pending .= $chunk;
            $lineStart = 0;
            while (($newline = strpos($pending, "\n", $lineStart)) !== false) {
                $end = $offset + $newline - $lineStart + 1 + $skipped;
                yield new Line($offset, self::text($offset, substr($pending, $lineStart, $newline - $lineStart)), $end, true, $skipped > 0);
                $offset = $end;
                $skipped = 0;
                $lineStart = $newline + 1;
            }

            $pending = substr($pending, $lineStart);
            if (strlen($pending) > $this->maximumLineBytes) {
                $skipped += strlen($pending) - $this->maximumLineBytes;
                $pending = substr($pending, 0, $this->maximumLineBytes);
            }
        }

        if ($pending !== '') {
            yield new Line($offset, self::text($offset, $pending), $offset + strlen($pending) + $skipped, false, $skipped > 0);
        }
    }

    public static function withoutByteOrderMark(string $text): string
    {
        return str_starts_with($text, self::BYTE_ORDER_MARK) ? substr($text, strlen(self::BYTE_ORDER_MARK)) : $text;
    }

    private static function text(int $offset, string $line): string
    {
        $line = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;

        return $offset === 0 ? self::withoutByteOrderMark($line) : $line;
    }
}
