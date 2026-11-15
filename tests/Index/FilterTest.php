<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Index;

use Hyphaeworks\Demonolog\Index\Filter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterTest extends TestCase
{
    #[Test]
    public function rejectsAnEmptyChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A channel has a name');

        new Filter(channels: ['app', '']);
    }
}
