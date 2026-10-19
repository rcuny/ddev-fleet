<?php

declare(strict_types=1);

/*
 * One of two concurrent secret writers of ConcurrentSecretWritesTest (a child process on the test FLEET_HOME).
 *
 * Usage: php secret_writer.php <a|b> <flag-dir> <scope-id> <rounds>
 *
 * Each round, writer A opens a transaction, reads, writes and keeps the transaction open until writer B announces that
 * it is about to start its own; B then reads and writes. The writers hand over through flag files (bounded waits, no
 * sleeps in the test itself), so B always starts while A holds the write lock. Exit 0 = both writers saved every round.
 */

use App\Domain\Secret\Secret;
use App\Domain\Secret\SecretRepository;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[, $role, $flags, $scopeId, $rounds] = $argv + [null, null, null, null, null];
if (!in_array($role, ['a', 'b'], true) || !is_string($flags) || !is_string($scopeId) || !ctype_digit((string) $rounds)) {
    fwrite(\STDERR, "usage: secret_writer.php <a|b> <flag-dir> <scope-id> <rounds>\n");
    exit(2);
}

$waitFor = static function (string $flag) use ($flags): void {
    $deadline = microtime(true) + 20;
    while (!is_file($flags.'/'.$flag)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException(sprintf('Timed out waiting for the flag "%s".', $flag));
        }
        usleep(2000);
    }
};
$raise = static fn (string $flag): bool => false !== file_put_contents($flags.'/'.$flag, '1');

$kernel = new Kernel('test', true);
$kernel->boot();

try {
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    assert($em instanceof EntityManagerInterface);
    $secrets = new SecretRepository($em);
    $write = static function (string $name) use ($secrets, $scopeId): void {
        $secrets->transactional(static function () use ($secrets, $scopeId, $name): void {
            if (null === $secrets->find(Secret::SCOPE_PROJECT, $scopeId, $name)) {
                $secrets->save(new Secret(Secret::SCOPE_PROJECT, $scopeId, $name, 'cipher', 'nonce', 1, 'test'));
            }
        });
    };

    for ($round = 1; $round <= (int) $rounds; ++$round) {
        if ('a' === $role) {
            // Not before B has finished the previous round, or A would take the lock again ahead of B.
            $round > 1 && $waitFor('b-done-'.($round - 1));
            $secrets->transactional(static function () use ($secrets, $scopeId, $round, $raise, $waitFor): void {
                $secrets->find(Secret::SCOPE_PROJECT, $scopeId, 'A_'.$round);
                $secrets->save(new Secret(Secret::SCOPE_PROJECT, $scopeId, 'A_'.$round, 'cipher', 'nonce', 1, 'test'));
                $raise('a-in-tx-'.$round);
                $waitFor('b-starting-'.$round);
            });
        } else {
            $waitFor('a-in-tx-'.$round);
            $raise('b-starting-'.$round);
            $write('B_'.$round);
            $raise('b-done-'.$round);
        }
    }
} catch (Throwable $e) {
    fwrite(\STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(1);
} finally {
    $kernel->shutdown();
}
