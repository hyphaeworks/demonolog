<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Reading;

use Closure;
use Generator;

/**
 * @internal
 */
final readonly class RecordFramer
{
    private Lines $lines;

    /**
     * @param Closure(string): bool $startsRecord
     */
    public function __construct(
        private Closure $startsRecord,
        private int $maximumRecordBytes = 1048576,
    ) {
        $this->lines = new Lines($maximumRecordBytes);
    }

    /**
     * Returns where a later read resumes. Monolog writes each record at once, ending with a newline.
     * A last line without a newline is still being written, so it is held back.
     *
     * @param iterable<string> $chunks
     *
     * @return Generator<int, RawRecord, mixed, int>
     */
    public function records(iterable $chunks, int $offset = 0, bool $holdPartialTail = false): Generator
    {
        $open = null;
        $resumeAt = $offset;

        foreach ($this->lines->of($chunks, $offset) as $line) {
            $stillBeingWritten = $holdPartialTail && !$line->terminated;

            if ($open !== null && !$this->startsRecord($open, $line)) {
                if ($stillBeingWritten) {
                    return $open->offset;
                }
                $open->append($line);
                $resumeAt = $line->end;
                continue;
            }

            if ($open !== null) {
                yield $open->toRecord();
            }
            if ($stillBeingWritten) {
                return $line->offset;
            }
            $open = new OpenRecord($line);
            $resumeAt = $line->end;
        }

        if ($open !== null) {
            yield $open->toRecord();
        }

        return $resumeAt;
    }

    private function startsRecord(OpenRecord $open, Line $line): bool
    {
        return ($this->startsRecord)($line->text) || $open->bytesWith($line) > $this->maximumRecordBytes;
    }
}
