<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Format;

use Hyphaeworks\Demonolog\Format\Header;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeaderTest extends TestCase
{
    #[Test]
    public function rejectsAnEmptyChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A channel has a name, or is null');

        new Header(null, '');
    }
}
