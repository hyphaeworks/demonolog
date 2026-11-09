<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Reading\RawRecord;

interface Format
{
    public function detect(string $sample): bool;

    public function startsRecord(string $line): bool;

    public function parse(RawRecord $record): Entry;
}
