<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Tests\Entry;

use Hyphaeworks\Demonolog\Entry\Level;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    #[Test]
    public function resolvesMonologLevelNames(): void
    {
        self::assertSame(Level::Debug, Level::fromName('DEBUG'));
        self::assertSame(Level::Emergency, Level::fromName('emergency'));
    }

    #[Test]
    public function resolvesLevelNamesTruncatedByMaxLevelNameLength(): void
    {
        self::assertSame(Level::Error, Level::fromName('ERR'));
        self::assertSame(Level::Critical, Level::fromName('CRIT'));
        self::assertSame(Level::Emergency, Level::fromName('EMERG'));
        self::assertSame(Level::Warning, Level::fromName('WARN'));
    }

    #[Test]
    public function ordersLevelsBySeverity(): void
    {
        self::assertTrue(Level::Error->isAtLeast(Level::Warning));
        self::assertFalse(Level::Info->isAtLeast(Level::Warning));
    }

    #[Test]
    public function returnsNullForUnknownLevelNames(): void
    {
        self::assertNull(Level::fromName('LOUD'));
        self::assertNull(Level::fromName('TRACE'));
        self::assertNull(Level::fromName('ER'));
        self::assertNull(Level::fromName('ERRORS'));
    }
}
