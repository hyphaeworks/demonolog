<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format\Line;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Format\Timestamps;
use Hyphaeworks\Demonolog\Reading\RawRecord;

final readonly class LineFormat implements Format
{
    private const HEADER = '/^\[(?<datetime>[^\]]{8,64})\] (?<channel>[^\s:]+?)\.(?<level>[A-Za-z]{1,9}): (?<rest>.*)\z/s';
    private const RECORD_START = '/^\[[^\]]{8,64}\] [^\s:]+?\.[A-Za-z]{1,9}: /';

    public function __construct(
        private Timestamps $timestamps = new Timestamps(),
        private ContextSplitter $contextSplitter = new ContextSplitter(),
        private ExceptionText $exceptionText = new ExceptionText(),
    ) {
    }

    public function detect(string $sample): bool
    {
        return $this->startsRecord(explode("\n", ltrim($sample), 2)[0]);
    }

    public function startsRecord(string $line): bool
    {
        return str_starts_with($line, '[') && preg_match(self::RECORD_START, $line) === 1;
    }

    public function parse(RawRecord $record): Entry
    {
        $matched = preg_match(self::HEADER, $record->text, $header);
        if ($matched !== 1) {
            return Entry::unparsed($record->offset, $record->text, $matched === false
                ? new Warning(WarningCode::Pcre, preg_last_error_msg())
                : new Warning(WarningCode::Unparsed, 'Not a Monolog line record'));
        }

        $split = $this->contextSplitter->split($header['rest']);
        $timestamp = $this->timestamps->parse($header['datetime']);

        $warnings = [];
        if ($timestamp === null) {
            $warnings[] = new Warning(WarningCode::Timestamp, sprintf('"%s" is not a known timestamp format', $header['datetime']));
        }
        if ($split['undecodable']) {
            $warnings[] = new Warning(WarningCode::ContextJson, 'Context or extra is not valid JSON');
        }

        return new Entry(
            offset: $record->offset,
            raw: $record->text,
            timestamp: $timestamp,
            channel: $header['channel'],
            level: Level::fromName($header['level']),
            levelName: $header['level'],
            message: $split['message'],
            context: $split['context'],
            extra: $split['extra'],
            exception: is_string($split['context']['exception'] ?? null) ? $this->exceptionText->parse($split['context']['exception']) : null,
            warnings: $warnings,
        );
    }
}
