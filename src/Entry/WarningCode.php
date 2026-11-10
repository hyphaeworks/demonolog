<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Entry;

enum WarningCode
{
    case Unparsed;
    case ContextJson;
    case Timestamp;
    case Pcre;
    case Truncated;
}
