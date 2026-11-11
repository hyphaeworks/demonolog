<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\LoggedException;

final class PhpStacktrace implements Stacktrace
{
    private const FRAMES = '/^#(?<index>\d+) (?:\{main\}|(?:(?<file>.+?)\((?<line>\d+)\): |\[internal function\]: |\[unknown file\]: )?(?:(?<class>[^\s(:]+)(?<type>->|::))?(?<function>(?:\{[^}\n]*\}|[^(\s{])+)\((?<arguments>.*)\))$/m';
    private const EVALUATED_CODE = '/^(?<file>.*)\((?<line>\d+)\) : (?:eval\(\)\'d code|runtime-created function)$/';

    /**
     * @var list<Frame>|null
     */
    private ?array $frames = null;

    public function __construct(
        public readonly string $text,
        private readonly ApplicationFrames $applicationFrames,
    ) {
    }

    public function frames(): array
    {
        if ($this->frames !== null) {
            return $this->frames;
        }

        preg_match_all(self::FRAMES, $this->text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $inApp = $this->applicationFrames->mark(array_column($matches, 'file'));

        return $this->frames = array_map($this->frame(...), $matches, $inApp);
    }

    /**
     * @param array<string|int, string|null> $frame
     */
    private function frame(array $frame, bool $inApp): Frame
    {
        $index = (int)$frame['index'];
        if ($frame['function'] === null) {
            return Frame::main($index, $inApp);
        }

        $location = null;
        if ($frame['file'] !== null) {
            $location = str_contains($frame['file'], ') : ') && preg_match(self::EVALUATED_CODE, $frame['file'], $evaluated) === 1
                ? Location::fromLog($evaluated['file'], (int)$evaluated['line'])
                : Location::fromLog($frame['file'], (int)$frame['line']);
        }

        return Frame::call(
            index: $index,
            location: $location,
            class: $frame['class'],
            callType: $frame['type'] === null ? null : CallType::from($frame['type']),
            function: $frame['function'],
            arguments: (string)$frame['arguments'],
            inApp: $inApp,
        );
    }
}
