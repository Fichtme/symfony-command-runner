<?php

declare(strict_types=1);

[$action, $arguments] = [$argv[1] ?? '', array_slice($argv, 2)];

if ($action === 'success') {
    [$file, $value] = $arguments;
    file_put_contents($file, $value . "\n", FILE_APPEND | LOCK_EX);
    exit(0);
}

if ($action === 'fail') {
    fwrite(STDERR, ($arguments[0] ?? 'failure') . "\n");
    exit(7);
}

if ($action === 'silent-fail') {
    exit(7);
}

if ($action !== 'barrier') {
    fwrite(STDERR, "Unknown fixture action.\n");
    exit(64);
}

[$stateFile, $limit] = [$arguments[0], (int) $arguments[1]];
$handle = fopen($stateFile, 'c+');
if ($handle === false) {
    fwrite(STDERR, "Unable to open state file.\n");
    exit(74);
}

flock($handle, LOCK_EX);
$contents = stream_get_contents($handle);
$state = $contents === '' ? [] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($state)) {
    $state = [];
}
$state += ['active' => 0, 'maximum' => 0, 'arrived' => 0, 'generation' => 0];
++ $state['active'];
++ $state['arrived'];
$state['maximum'] = max($state['maximum'], $state['active']);
$generation = intdiv($state['arrived'] - 1, $limit) + 1;
if ($state['active'] >= $limit) {
    $state['generation'] = max($state['generation'], $generation);
}
writeState($handle, $state);
flock($handle, LOCK_UN);

do {
    usleep(1000);
    flock($handle, LOCK_SH);
    rewind($handle);
    $current = json_decode((string) stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
    flock($handle, LOCK_UN);
} while (($current['generation'] ?? 0) < $generation);

flock($handle, LOCK_EX);
rewind($handle);
$state = json_decode((string) stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
--$state['active'];
writeState($handle, $state);
flock($handle, LOCK_UN);
fclose($handle);

/** @param resource $handle */
function writeState($handle, array $state): void
{
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
    fflush($handle);
}
