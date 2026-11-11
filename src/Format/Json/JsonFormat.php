<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format\Json;

use Hyphaeworks\Demonolog\Entry\Entry;
use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Entry\Warning;
use Hyphaeworks\Demonolog\Entry\WarningCode;
use Hyphaeworks\Demonolog\Format\Format;
use Hyphaeworks\Demonolog\Format\Timestamps;
use Hyphaeworks\Demonolog\LoggedException\ApplicationFrames;
use Hyphaeworks\Demonolog\LoggedException\Location;
use Hyphaeworks\Demonolog\LoggedException\LoggedException;
use Hyphaeworks\Demonolog\Reading\RawRecord;
use JsonException;

final readonly class JsonFormat implements Format
{
    public function __construct(
        private Timestamps $timestamps = new Timestamps(),
        private ApplicationFrames $applicationFrames = new ApplicationFrames(),
    ) {
    }

    /**
     * A file may start in the middle of a record, after a copy-truncate rotation, so any line of the sample counts.
     * The last line may be cut by the end of the sample. Monolog writes the message first, so its start is enough.
     */
    public function detect(string $sample): bool
    {
        $lines = explode("\n", $sample);
        foreach ($lines as $line) {
            if ($this->isRecord(trim($line))) {
                return true;
            }
        }

        return str_starts_with(trim($lines[count($lines) - 1]), '{"message":');
    }

    public function startsRecord(string $line): bool
    {
        return str_starts_with($line, '{');
    }

    public function parse(RawRecord $record): Entry
    {
        try {
            $fields = json_decode($record->text, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $exception) {
            return Entry::unparsed($record->offset, $record->text, new Warning(WarningCode::Unparsed, $exception->getMessage()));
        }
        if (!is_array($fields)) {
            return Entry::unparsed($record->offset, $record->text, new Warning(WarningCode::Unparsed, 'Not a JSON object'));
        }

        $datetime = $fields['datetime'] ?? '';
        if (is_array($datetime) && is_string($datetime['date'] ?? null) && is_string($datetime['timezone'] ?? null)) {
            $datetime = $datetime['date'] . ' ' . $datetime['timezone'];
        }
        $datetime = is_string($datetime) ? $datetime : '';
        $levelName = is_string($fields['level_name'] ?? null) ? $fields['level_name'] : '';
        $timestamp = $this->timestamps->parse($datetime);
        $context = is_array($fields['context'] ?? null) ? $fields['context'] : [];

        return new Entry(
            offset: $record->offset,
            raw: $record->text,
            timestamp: $timestamp,
            channel: is_string($fields['channel'] ?? null) ? $fields['channel'] : '',
            level: (is_int($fields['level'] ?? null) ? Level::tryFrom($fields['level']) : null) ?? Level::fromName($levelName),
            levelName: $levelName,
            message: is_string($fields['message'] ?? null) ? $fields['message'] : '',
            context: $context,
            extra: is_array($fields['extra'] ?? null) ? $fields['extra'] : [],
            exception: is_array($context['exception'] ?? null) ? $this->exception($context['exception']) : null,
            warnings: $timestamp === null ? [new Warning(WarningCode::Timestamp, sprintf('"%s" is not a known timestamp format', $datetime))] : [],
        );
    }

    private function isRecord(string $line): bool
    {
        if (!str_starts_with($line, '{')) {
            return false;
        }
        try {
            $fields = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return false;
        }

        return is_array($fields) && array_key_exists('message', $fields) && array_key_exists('datetime', $fields);
    }

    /**
     * @param array<array-key, mixed> $normalized
     */
    private function exception(array $normalized): ?LoggedException
    {
        if (!is_string($normalized['class'] ?? null)) {
            return null;
        }
        $locations = [];
        foreach (is_array($normalized['trace'] ?? null) ? $normalized['trace'] : [] as $location) {
            $locations[] = is_string($location) ? $location : '';
        }

        return new LoggedException(
            type: $normalized['class'],
            message: is_string($normalized['message'] ?? null) ? $normalized['message'] : '',
            code: is_int($normalized['code'] ?? null) || is_string($normalized['code'] ?? null) ? $normalized['code'] : 0,
            location: Location::parse(is_string($normalized['file'] ?? null) ? $normalized['file'] : ''),
            stacktrace: $locations === [] ? null : new JsonStacktrace($locations, $this->applicationFrames),
            previous: is_array($normalized['previous'] ?? null) ? $this->exception($normalized['previous']) : null,
        );
    }
}
