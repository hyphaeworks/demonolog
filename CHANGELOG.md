# Changelog

Until 1.0, a minor version may break the API. Each break is listed here.

## Unreleased

Added:

- `EntryIndex::entryAt()` reads the entry at a position from the file the
  index reads.
- `EntryIndex::channels()` lists the channels of the file.

Changed:

- `EntryIndex::grow()` returns a `Growth` instead of a bool. It has
  `atEnd()`, `restarted`, and `indexedBytes` and `fileBytes` for a progress
  bar. It cannot report more bytes than the file has.
- `Entry::$channel` and `Header::$channel` are null when a record has no
  channel, instead of an empty string. An empty channel name is rejected.
- `Location::$line` is null when the line is not known, instead of 0. A
  location needs a file, so `Location::parse()` returns null for empty text,
  and `LoggedException::$location` is null for an exception without a file.
  `Location::fromLog()` reads PHP's line 0 as no line.
- An `Entry` rejects a level that contradicts a known level name. A JSON record
  takes its level from the name, and from the number only when the name is
  unknown.
- `LineFormat` takes `Timestamps` and `ApplicationFrames`, like `JsonFormat`.
  It no longer takes the internal `ContextSplitter` and `ExceptionText`.
- `EntryIndex` no longer takes a `Filter` in its constructor. Use `filter()`,
  which replaces any filter the index had.
- `LogFile::follow()` throws an `InvalidArgumentException` for a negative
  offset, instead of a follower that never reads.
- An `Update` that is not a restart cannot have entries from a previous file.
- `LogFile::tail(0)` returns no entries instead of failing.
- `EntryIndex::offsetAt()` throws an `OutOfRangeException` for a position
  outside the index.

Fixed:

- A file that starts with a byte order mark is read.
- JSON is detected in a file that starts in the middle of a record, and when
  the first record is longer than the 64 KiB sample.
- The index starts over when the file was rotated, truncated or rewritten,
  instead of keeping old entries.
- The index adds lines appended without a header to the last entry, as
  `entries()` does.
- A channel filter treats channels beyond the 65,535th the same before and
  after the index grows: they never match.
- The index text filter searches the full bytes of a record.
- The index includes the last record of a file without a final newline.
- A line cut at the 1 MiB record cap comes with a `Truncated` warning.
- Frames in a Windows `vendor\` directory are marked.
- A JSON record with an unknown level number takes the level from its name.

## 0.1.0 (2026-11-10)

First release.

- Reads Monolog's line and JSON formats from Monolog 1, 2 and 3, as written by
  Symfony, Laravel and plain Monolog, and detects which one a file uses.
- Parses logged exceptions with their previous exceptions and stack frames, and
  marks the frames outside `vendor/`.
- Returns a record it cannot read as an entry with a warning.
- `LogFile::tail()` reads the last entries from the end of the file.
- `LogFile::follow()` returns new entries as a file grows, and notices
  truncation, rotation and rewritten files.
- `LogFile::index()` builds a packed index that filters by level, channel and
  text, and `LogFile::entryAt()` reads one entry from its offset.
- Other packages can add formats through the `Format` interface.
