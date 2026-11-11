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

The follower switches to a rotated file on its own, but the `LogFile` keeps
reading the old one. After `restarted`, open the file again before you use
`entryAt()`, `tail()` or `index()`.

## Index and filter

```php
use Hyphaeworks\Demonolog\Entry\Level;
use Hyphaeworks\Demonolog\Index\Filter;

$index = $log->index();
$index->grow();

$errors = $index->filter(new Filter(minimumLevel: Level::Error, channels: ['app'], text: 'payment'));

for ($row = 0; $row < count($errors); ++$row) {
    echo $log->entryAt($errors->offsetAt($row))?->message, "\n";
}
```

`grow(maximumBytes: 1_000_000)` reads in steps, and a later `grow()` adds the
entries written since. A filtered index keeps its filter when it grows.

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
