<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format\Json;

use Hyphaeworks\Demonolog\LoggedException\ApplicationFrames;
use Hyphaeworks\Demonolog\LoggedException\Frame;
use Hyphaeworks\Demonolog\LoggedException\Location;
use Hyphaeworks\Demonolog\LoggedException\Stacktrace;

final class JsonStacktrace implements Stacktrace
{
    /**
     * @var list<Frame>|null
     */
    private ?array $frames = null;

    /**
     * @param list<string> $locations
     */
    public function __construct(
        public readonly array $locations,
        private readonly ApplicationFrames $applicationFrames,
    ) {
    }

    public function frames(): array
    {
        if ($this->frames !== null) {
            return $this->frames;
        }

        $locations = [];
        foreach ($this->locations as $text) {
            $location = Location::parse($text);
            if ($location !== null) {
                $locations[] = $location;
            }
        }
        $inApp = $this->applicationFrames->mark(array_column($locations, 'file'));

        $frames = [];
        foreach ($locations as $index => $location) {
            $frames[] = Frame::at($index, $location, $inApp[$index]);
        }

        return $this->frames = $frames;
    }
}
