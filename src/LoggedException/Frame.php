<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

use InvalidArgumentException;

final readonly class Frame
{
    private function __construct(
        public int $index,
        public ?Location $location,
        public ?string $class,
        public ?CallType $callType,
        public ?string $function,
        public ?string $arguments,
        public bool $inApp,
    ) {
        if ($index < 0) {
            throw new InvalidArgumentException(sprintf('Frame index %d is negative', $index));
        }
        if (($class === null) !== ($callType === null)) {
            throw new InvalidArgumentException('A frame has a class and a call type, or neither');
        }
    }

    public static function call(
        int $index,
        ?Location $location,
        ?string $class,
        ?CallType $callType,
        string $function,
        string $arguments,
        bool $inApp,
    ): self {
        return new self($index, $location, $class, $callType, $function, $arguments, $inApp);
    }

    public static function main(int $index, bool $inApp): self
    {
        return new self($index, null, null, null, '{main}', null, $inApp);
    }

    public static function at(int $index, Location $location, bool $inApp): self
    {
        return new self($index, $location, null, null, null, null, $inApp);
    }
}
