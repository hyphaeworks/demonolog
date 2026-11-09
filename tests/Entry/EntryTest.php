<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Entry;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase
{
    #[Test]
    public function rejectsANegativeOffset(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Entry::unparsed(-1, 'garbage', new Warning(WarningCode::Unparsed, 'Not a Monolog line record'));
    }
}
