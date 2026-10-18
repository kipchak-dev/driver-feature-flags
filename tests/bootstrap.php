<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The Unleash SDK asserts that $_SERVER['REMOTE_ADDR'] exists whenever it reads the context's IP address, which
// is a warning on the command line.
$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
