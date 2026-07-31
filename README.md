# Symfony Command Runner

Run multiple Symfony console commands asynchronously and wait for all subprocesses to finish.

## Requirements

- PHP 8.2, 8.3, 8.4 or 8.5
- Symfony Console, Lock and Process 6.4, 7.4 or 8.x
- Doctrine Collections 1.8, 2.x or 3.x (Collections 3.x itself requires a sufficiently recent PHP version; Composer selects an older supported major on older runtimes)

Install the package with Composer:

```shell
composer require fichtme/symfony-command-runner:^5.0
```

## Usage

The runner prefixes every supplied process command line with the configured PHP binary and the current script path. In a Symfony command, the current script is normally `bin/console`.

```php
<?php

declare(strict_types=1);

use Fichtme\CommandRunner\CommandRunner;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/** @var SymfonyStyle $io */
$runner = (new CommandRunner([
    new Process(['app:import', '--quiet']),
    new Process(['app:reindex', '--quiet']),
    new Process(['app:notify', '--env=prod']),
]))
    ->continueOnError(true)
    ->setIO($io)
    ->setLimit(3);

$runner->run();

foreach ($runner->getErrors() as $error) {
    // $error contains the executed command line and its error output.
}
```

When running outside a conventional Symfony entry point, set the console script explicitly:

```php
$runner
    ->setBinary(PHP_BINARY)
    ->setSubPath(__DIR__ . '/bin/console')
    ->run();
```

## Locking

`CommandRunner::lock()` uses Symfony's local `FlockStore`. It is suitable only when all competing processes use the same host and filesystem.

```php
$lock = CommandRunner::lock('app:import', 'tenant-42');

try {
    // Run the protected work.
} finally {
    $lock->release();
}
```

For backwards compatibility, failure to find a PHP binary and failure to acquire the requested lock still terminate the current process with `exit()`. Avoid lock contention in long-running workers that must retain control over their own lifecycle.

## Development

```shell
composer validate --strict
composer update
vendor/bin/phpunit
vendor/bin/phpstan analyse
composer audit
```
