<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

enum CallType: string
{
    case Instance = '->';
    case Static = '::';
}
