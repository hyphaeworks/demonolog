<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Entry;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Level;
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

    #[Test]
    public function rejectsALevelThatContradictsItsName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Level Error contradicts the level name "info"');

        new Entry(0, 'text', null, 'app', Level::Error, 'info', 'text');
    }

    #[Test]
    public function keepsTheLevelOfANameItDoesNotKnow(): void
    {
        $entry = new Entry(0, 'text', null, 'app', Level::Error, 'FATAL', 'text');

        self::assertSame([Level::Error, 'FATAL'], [$entry->level, $entry->levelName]);
    }

    #[Test]
    public function rejectsAnEmptyChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A channel has a name, or is null');

        new Entry(0, 'text', null, '', null, '', 'text');
    }
}
