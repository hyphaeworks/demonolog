<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format;

use Hyphaeworks\Demonolog\Reading\RawRecord;

/**
 * Optional for a format. The index then reads only the header of each record, which is several times faster than a full parse.
 */
interface ReadsHeaders
{
    public function header(RawRecord $record): Header;
}
