<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format\Line;

use Hyphaeworks\Demonolog\LoggedException\ApplicationFrames;
use Hyphaeworks\Demonolog\LoggedException\Location;
use Hyphaeworks\Demonolog\LoggedException\LoggedException;
use Hyphaeworks\Demonolog\LoggedException\PhpStacktrace;

/**
 * @internal
 */
final readonly class ExceptionText
{
    private const PREVIOUS_ON_OWN_LINE = "\n[previous exception] ";
    private const DEPTH_GUARD = '/^Over \d+ levels deep, aborting normalization/';
    private const HEADER = '/^\[object\] \((?<body>.*)\)$/s';
    private const PREVIOUS_INLINE = '/, (?=[^\s(),]+\(code: )/';
    private const EXCEPTION = '/^(?<type>[^\s(]+)\(code: (?<code>-?\d+|[^)]*?)(?: faultcode: .*?)?\): (?<message>.*) at (?<file>.+?):(?<line>\d+)$/s';
    private const STACKTRACE_START = '/\n(?<indent>[ \t]*)\[stacktrace\]\n/';

    public function __construct(
        private ApplicationFrames $applicationFrames = new ApplicationFrames(),
    ) {
    }

    public function parse(string $text): ?LoggedException
    {
        $previous = null;
        $truncated = false;
        foreach (array_reverse(explode(self::PREVIOUS_ON_OWN_LINE, $text)) as $section) {
            if (preg_match(self::DEPTH_GUARD, $section) === 1) {
                $truncated = $previous === null;
                continue;
            }
            $previous = $this->parseException($section, $previous, $truncated);
            if ($previous === null) {
                return null;
            }
            $truncated = false;
        }

        return $previous;
    }

    private function parseException(string $text, ?LoggedException $previous, bool $truncated): ?LoggedException
    {
        $stacktrace = null;
        if (preg_match(self::STACKTRACE_START, $text, $start, PREG_OFFSET_CAPTURE) === 1) {
            $stacktrace = new PhpStacktrace(
                $this->unindent(substr($text, $start[0][1] + strlen($start[0][0])), $start['indent'][0]),
                $this->applicationFrames,
            );
            $text = substr($text, 0, $start[0][1]);
        }

        if (preg_match(self::HEADER, $text, $header) !== 1) {
            return null;
        }

        $exceptions = preg_split(self::PREVIOUS_INLINE, $header['body']);
        if ($exceptions === false) {
            return null;
        }
        foreach (array_reverse($exceptions, true) as $index => $exception) {
            if (preg_match(self::EXCEPTION, $exception, $parts) !== 1) {
                return null;
            }
            $previous = new LoggedException(
                type: $parts['type'],
                message: $parts['message'],
                code: preg_match('/^-?\d+$/', $parts['code']) === 1 ? (int)$parts['code'] : $parts['code'],
                location: new Location($parts['file'], (int)$parts['line']),
                stacktrace: $index === 0 ? $stacktrace : null,
                previous: $previous,
                truncated: $truncated && $index === count($exceptions) - 1,
            );
        }

        return $previous;
    }

    private function unindent(string $stacktrace, string $indent): string
    {
        $lines = explode("\n", rtrim($stacktrace, "\n"));
        if ($indent !== '') {
            $lines = array_map(
                static fn (string $line): string => str_starts_with($line, $indent) ? substr($line, strlen($indent)) : $line,
                $lines,
            );
        }

        return implode("\n", $lines);
    }
}
