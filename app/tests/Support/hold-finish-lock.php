<?php

declare(strict_types=1);

// Test helper (run as a separate PHP process): takes the setup-finish lock from the real flock store in the given
// directory, prints "locked", and holds it until its stdin is closed.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

$lock = new LockFactory(new FlockStore($argv[1]))->createLock('setup-finish', 60, false);
if (!$lock->acquire(false)) {
    fwrite(\STDERR, "could not lock\n");
    exit(2);
}
fwrite(\STDOUT, "locked\n");
fgets(\STDIN);
$lock->release();
