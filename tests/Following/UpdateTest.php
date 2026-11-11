<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Following;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Following\Update;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateTest extends TestCase
{
    #[Test]
    public function onlyARestartHasEntriesFromAPreviousFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a restart has entries from a previous file');

        new Update([], restarted: false, fromPreviousFile: [new Entry(0, 'old', null, 'app', null, '', 'old')]);
    }
}
