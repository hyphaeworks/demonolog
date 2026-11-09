<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final readonly class Timestamps
{
    public function __construct(
        private DateTimeZone $defaultZone = new DateTimeZone('UTC'),
    ) {
    }

    public function parse(string $datetime): ?DateTimeImmutable
    {
        if (trim($datetime) === '') {
            return null;
        }

        try {
            $timestamp = new DateTimeImmutable($datetime, $this->defaultZone);
        } catch (Exception) {
            return null;
        }

        return DateTimeImmutable::getLastErrors() === false ? $timestamp : null;
    }
}
