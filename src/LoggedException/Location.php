<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

use InvalidArgumentException;

final readonly class Location
{
    public function __construct(
        public string $file,
        public int $line,
    ) {
        if ($line < 0) {
            throw new InvalidArgumentException(sprintf('Line %d of %s is negative', $line, $file));
        }
    }

    public static function parse(string $location): self
    {
        if (preg_match('/^(?<file>.+):(?<line>\d+)$/', $location, $match) !== 1) {
            return new self($location, 0);
        }

        return new self($match['file'], (int)$match['line']);
    }
}
