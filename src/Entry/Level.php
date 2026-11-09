<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Entry;

enum Level: int
{
    case Debug = 100;
    case Info = 200;
    case Notice = 250;
    case Warning = 300;
    case Error = 400;
    case Critical = 500;
    case Alert = 550;
    case Emergency = 600;

    public static function fromName(string $name): ?self
    {
        if (strlen($name) < 3) {
            return null;
        }

        foreach (self::cases() as $level) {
            if (strncasecmp($level->name, $name, strlen($name)) === 0) {
                return $level;
            }
        }

        return null;
    }

    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }
}
