# Architecture: module-filesystem (Codeception)

## Purpose

The Codeception Filesystem module. Provides file and directory assertions for acceptance/functional tests: `seeFileFound()`, `seeInThisFile()`, `deleteFile()`, `writeToFile()`, and navigation helpers like `amInPath()`.

## Directory Structure

```
src/Codeception/Module/
  Filesystem.php   — All filesystem assertion and manipulation methods
```

## Key Design Decisions

- **Single-class design**: All filesystem helpers live in one Codeception module class for simplicity
- **Relative path resolution**: Methods resolve paths relative to the configured `root` path, defaulting to the project root
- **Stateful path navigation**: `amInPath()` changes the module's current working directory for subsequent calls in the same test

## Extension Points

- Inherit from `Filesystem` to add project-specific filesystem assertions
