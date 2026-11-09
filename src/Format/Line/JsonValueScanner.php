<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format\Line;

/**
 * @internal
 */
final class JsonValueScanner
{
    // All scans of one record share this budget, so a message full of brackets stays fast.
    private const BUDGET_PER_BYTE = 32;
    private const BUDGET_MINIMUM = 65536;

    public readonly int $contentEnd;
    private int $budget;

    /**
     * The second pass of ContextSplitter asks for this value again.
     *
     * @var array{int, int, string}|null
     */
    private ?array $valueAtContentEnd = null;

    public function __construct(
        private readonly string $text,
    ) {
        $this->contentEnd = strlen(rtrim($text));
        $this->budget = self::BUDGET_PER_BYTE * strlen($text) + self::BUDGET_MINIMUM;
    }

    /**
     * @return array{int, string}|null
     */
    public function valueAt(int $start): ?array
    {
        if ($this->valueAtContentEnd !== null
            && $this->valueAtContentEnd[0] === $start
            && $this->valueAtContentEnd[1] - $start <= $this->budget) {
            $this->budget -= $this->valueAtContentEnd[1] - $start;

            return [$this->valueAtContentEnd[1], $this->valueAtContentEnd[2]];
        }

        [$end, $json] = $this->scan($start, min(strlen($this->text), $start + max(0, $this->budget)));
        $this->budget -= $end - $start;
        if ($json === null) {
            return null;
        }
        if ($end >= $this->contentEnd) {
            $this->valueAtContentEnd ??= [$start, $end, $json];
        }

        return [$end, $json];
    }

    public function charge(int $bytes): void
    {
        $this->budget -= $bytes;
    }

    /**
     * Laravel leaves raw line breaks inside JSON strings. They are escaped again here.
     *
     * @return array{int, string|null}
     */
    private function scan(int $start, int $length): array
    {
        $text = $this->text;
        if ($start >= $length || ($text[$start] !== '{' && $text[$start] !== '[')) {
            return [$start, null];
        }

        $depth = 0;
        $position = $start;
        $segmentStart = $start;
        $repaired = '';
        while ($position < $length) {
            $character = $text[$position];
            if ($character === '"') {
                ++$position;
                while (true) {
                    $position += strcspn($text, "\"\\\n\r\t", $position, $length - $position);
                    if ($position >= $length) {
                        return [$length, null];
                    }
                    $character = $text[$position];
                    if ($character === '"') {
                        ++$position;
                        break;
                    }
                    if ($character === '\\') {
                        $position += 2;
                        if ($position >= $length) {
                            return [$length, null];
                        }
                        continue;
                    }
                    $repaired .= substr($text, $segmentStart, $position - $segmentStart) . match ($character) {
                        "\n" => '\n',
                        "\r" => '\r',
                        default => '\t',
                    };
                    $segmentStart = ++$position;
                }
            } else {
                $depth += match ($character) {
                    '{', '[' => 1,
                    '}', ']' => -1,
                    default => 0,
                };
                ++$position;
                if ($depth === 0) {
                    return [$position, $repaired . substr($text, $segmentStart, $position - $segmentStart)];
                }
            }
            $position += strcspn($text, '"{}[]', $position, $length - $position);
        }

        return [$length, null];
    }
}
