<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

use InvalidArgumentException;

final readonly class Location
{
    public function __construct(
        public string $file,
        public ?int $line,
    ) {
        if ($file === '') {
            throw new InvalidArgumentException('A location has a file');
        }
        if ($line !== null && $line < 1) {
            throw new InvalidArgumentException(sprintf('Line %d of %s is not a line', $line, $file));
        }
    }

    public static function parse(string $location): ?self
    {
        if (preg_match('/^(?<file>.+):(?<line>\d+)$/', $location, $match) !== 1) {
            return $location === '' ? null : new self($location, null);
        }

        return self::fromLog($match['file'], (int)$match['line']);
    }

    /**
     * PHP writes line 0 for a frame without a line.
     */
    public static function fromLog(string $file, int $line): ?self
    {
        return $file === '' ? null : new self($file, $line === 0 ? null : $line);
    }
}
