# Changelog

## 5.0.0 - Unreleased

### Changed

- Raised the runtime baseline to PHP 8.2 or newer.
- Raised the Symfony baseline to Console, Lock and Process 6.4, with support for Symfony 7.4 and 8.x.
- Raised the Doctrine Collections baseline to 1.8, with support for 1.8, 2.x and 3.x.
- Added native types and strict typing while retaining the existing `CommandRunner` methods and command execution behaviour.
- A failed subprocess exit code is now recorded by `getErrors()` even when the process writes nothing to standard error.
- Error reporting no longer requires a configured `SymfonyStyle` instance.

### Development

- Added subprocess, concurrency-limit, error-continuation, lifecycle, progress-output and local-lock coverage.
- Added PHPStan and a CI matrix covering PHP 8.2 through 8.5 and Symfony 6.4 through 8.1.

### Compatibility note

The dependency baseline changes are breaking: version 5.0.0 requires PHP >= 8.2, Symfony >= 6.4 and Doctrine Collections >= 1.8. The historical `exit()` behaviour when lock acquisition or PHP binary discovery fails remains unchanged for backwards compatibility.
