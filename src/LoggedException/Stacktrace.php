<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

interface Stacktrace
{
    /**
     * @return list<Frame>
     */
    public function frames(): array;
}
