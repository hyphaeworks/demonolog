# Changelog

Until 1.0, a minor version may break the API. Each break is listed here.

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
