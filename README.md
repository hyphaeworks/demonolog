# demonolog

**Read Monolog log files in PHP, as structured entries.**

Point it at a log file from Symfony, Laravel or plain Monolog, and you get each
record back with its time, level, channel, message, context and exception.

- **Both formats.** The default line format and the JSON format, detected for
  you. Monolog 1, 2 and 3 all work.
- **Exceptions as data.** The type, message, location and every stack frame of
  a logged exception, with the chain of previous exceptions. Frames inside
  `vendor/` are marked, so you can hide them.
- **No record is lost.** Every line of the file ends up in an entry. A record
  it cannot read comes back with a warning, never skipped and never thrown.
- **Flat memory.** Records are read one at a time, so a file of 100 MB or
  10 GB needs a few MiB.
- **Large files.** Read the last entries of a file without reading the rest.
  An index of the whole file takes 16 bytes per entry and filters by level,
  channel and text.
- **Follow a file.** New entries arrive as the file grows, and truncated or
  rotated files are noticed.

PHP 8.2+ · MIT License · No dependencies

## Status

0.1.0 is the first release. Until 1.0 a minor version may change the API, and
each change is listed in the [changelog](CHANGELOG.md).

## Install

```bash
composer require hyphaeworks/demonolog
```

## Read a file

```php
use Hyphaeworks\Demonolog\Demonolog;

$log = (new Demonolog())->open('var/log/dev.log');

foreach ($log->entries() as $entry) {
    echo $entry->timestamp?->format('H:i:s'), ' ', $entry->level?->name, ' ', $entry->message, "\n";

    foreach ($entry->exception?->frames() ?? [] as $frame) {
        if ($frame->inApp) {
            echo '  at ', $frame->class, $frame->callType?->value, $frame->function, "\n";
        }
    }
}
```

`entries()` reads one record at a time, so memory stays flat for any file size.
`fromString()` reads a log you already hold in memory.

## The last entries

```php
$entries = $log->tail(100);
```

`tail()` reads backwards from the end, like `tail -n`, so it is fast on a file
of any size.

## Follow a file

```php
$follower = $log->follow();

while (true) {
    $update = $follower->poll();
    if ($update->restarted) {
        // The file was truncated or rotated. $update->fromPreviousFile holds
        // the last entries of a rotated file.
    }
    foreach ($update->entries as $entry) {
        echo $entry->message, "\n";
    }
    sleep(1);
}
```

`poll()` never blocks. A record that is still being written waits for the next
poll.

The follower and the index switch to a rotated file on their own, but the
`LogFile` keeps reading the old one. After a rotation, open the file again
before you use its `entries()`, `entryAt()` or `tail()`.

## Index and filter

```php
use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Index\Filter;

$index = $log->index();
$index->grow();

$errors = $index->filter(new Filter(minimumLevel: Level::Error, channels: ['app'], text: 'payment'));

for ($row = 0; $row < count($errors); ++$row) {
    echo $errors->entryAt($row)?->message, "\n";
}
```

`grow(maximumBytes: 1_000_000)` reads in steps and returns a `Growth`. Call it
until `atEnd` is true, and use `indexedBytes` and `fileBytes` for a progress
bar:

```php
do {
    $growth = $index->grow(maximumBytes: 4_000_000);
    printf("\rIndexing %d of %d bytes", $growth->indexedBytes, $growth->fileBytes);
} while (!$growth->atEnd);
```

At the end both numbers are equal. An empty file reports 0 of 0. A later
`grow()` adds the entries written since. A filtered index keeps its filter when
it grows.

When the file was rotated, truncated or rewritten, `grow()` reads it again from
the start and reports `restarted`, so a viewer knows its rows are gone.
`entryAt()` reads from the same file as the index, so a row always matches its
entry.

## Warnings

An entry that was not read cleanly still comes back, with a list of
`$entry->warnings`. Each warning has a `code` and a `message`:

| `WarningCode` | Meaning |
|---------------|---------|
| `Unparsed`    | The record is not in the format, so `message` holds the raw text. |
| `ContextJson` | The context or extra of a line record is not valid JSON. |
| `Timestamp`   | The time is not in a known format, so `timestamp` is null. |
| `Pcre`        | A regular expression hit a PCRE limit. |
| `Truncated`   | A line was longer than 1 MiB, so its end is missing. |

## Time zone and vendor paths

A time without a zone is read as UTC. Frames whose file is inside `vendor/`
have `inApp` set to false. To change either, pass both formats with your
settings:

```php
use Hyphaeworks\Demonolog\Format\Json\JsonFormat;
use Hyphaeworks\Demonolog\Format\Line\LineFormat;
use Hyphaeworks\Demonolog\Format\Timestamps;
use Hyphaeworks\Demonolog\LoggedException\ApplicationFrames;

$timestamps = new Timestamps(new DateTimeZone('Europe/Vienna'));
$frames = new ApplicationFrames(['vendor/', 'lib/']);
$demonolog = new Demonolog(new LineFormat($timestamps, $frames), new JsonFormat($timestamps, $frames));
```

## Another format

A format is a class with three methods: `detect()` checks a sample of the file,
`startsRecord()` says whether a line begins a record, and `parse()` turns a
record into an `Entry`. Pass it to `Demonolog` and it is tried first:

```php
$demonolog = new Demonolog(new MyFormat());
```

`open($path, new MyFormat())` skips detection.

## License

MIT
