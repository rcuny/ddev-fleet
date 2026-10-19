<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use App\Tests\Repo\Support\InstallerHarness;
use PHPUnit\Framework\TestCase;

/**
 * The DNS pre-check compares the A and AAAA records of <domain> and auth.<domain> with this host's own addresses
 * (interface addresses, or the public address behind NAT). dig, ip and curl are stubs on PATH.
 */
final class InstallerDnsCheckTest extends TestCase
{
    use InstallerHarness;

    private const string DOMAIN = 'fleet.example.com';
    private const string AUTH = 'auth.fleet.example.com';

    public function testMatchingLocalAddressPasses(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['203.0.113.7']], self::AUTH => ['4' => ['203.0.113.7']]],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, $out, $err] = $this->check($stubs);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('DNS ok', $out);
        self::assertStringContainsString('==> 10-packages', $out);
    }

    public function testWildcardStyleRecordsPassWhenBothNamesResolveToThisHost(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['203.0.113.7']], self::AUTH => ['4' => ['203.0.113.7']]],
            localV4: ['10.0.0.5', '203.0.113.7'],
            publicV4: null,
        );

        self::assertSame(0, $this->check($stubs)[0]);
    }

    public function testPublicAddressBehindNatPasses(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['198.51.100.9']], self::AUTH => ['4' => ['198.51.100.9']]],
            localV4: ['10.0.0.5'],
            publicV4: '198.51.100.9',
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(0, $status, $err);
    }

    public function testMismatchExits2(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['192.0.2.1']], self::AUTH => ['4' => ['192.0.2.1']]],
            localV4: ['203.0.113.7'],
            publicV4: '203.0.113.7',
        );

        [$status, $out, $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringNotContainsString('==>', $out, 'no step may run after a DNS mismatch');
        self::assertStringContainsString('fleet.example.com has the A record 192.0.2.1', $err);
        self::assertStringContainsString('203.0.113.7', $err, 'the message names the addresses of this host');
        self::assertStringContainsString('--skip-dns-check', $err);
    }

    public function testAuthSubdomainMismatchExits2(): void
    {
        // The login portal lives at auth.<domain>; HTTP-01 needs its own record.
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['203.0.113.7']], self::AUTH => ['4' => ['192.0.2.1']]],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('auth.fleet.example.com has the A record 192.0.2.1', $err);
        self::assertStringNotContainsString('ERROR: fleet.example.com ', $err);
    }

    public function testMissingARecordExits2(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['203.0.113.7']]],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('auth.fleet.example.com has no A record', $err);
    }

    public function testOneForeignRecordAmongSeveralExits2(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['203.0.113.7', '192.0.2.44']], self::AUTH => ['4' => ['203.0.113.7']]],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('192.0.2.44', $err);
    }

    public function testStaleAaaaRecordExits2(): void
    {
        $stubs = $this->dnsStubs(
            [
                self::DOMAIN => ['4' => ['203.0.113.7'], '6' => ['2001:db8::99']],
                self::AUTH => ['4' => ['203.0.113.7']],
            ],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('AAAA record 2001:db8::99', $err);
        self::assertStringContainsString('prefers IPv6', $err);
    }

    public function testMatchingAaaaPasses(): void
    {
        $stubs = $this->dnsStubs(
            [
                self::DOMAIN => ['4' => ['203.0.113.7'], '6' => ['2001:DB8::1']],
                self::AUTH => ['4' => ['203.0.113.7'], '6' => ['2001:db8::1']],
            ],
            localV4: ['203.0.113.7'],
            localV6: ['2001:db8::1'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(0, $status, $err);
    }

    public function testAaaaMatchingThePublicAddressPasses(): void
    {
        $stubs = $this->dnsStubs(
            [
                self::DOMAIN => ['4' => ['203.0.113.7'], '6' => ['2001:db8::1']],
                self::AUTH => ['4' => ['203.0.113.7'], '6' => ['2001:db8::1']],
            ],
            localV4: ['203.0.113.7'],
            publicV4: null,
            publicV6: '2001:db8::1',
        );

        self::assertSame(0, $this->check($stubs)[0]);
    }

    public function testTheResolverIsQueriedNotEtcHosts(): void
    {
        // getent (which reads /etc/hosts) would claim 203.0.113.7; the resolver says 192.0.2.1.
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['192.0.2.1']], self::AUTH => ['4' => ['192.0.2.1']]],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('192.0.2.1', $err);
    }

    public function testLoopbackAnswerFromTheStubResolverIsRetriedAgainstAPublicResolver(): void
    {
        // systemd-resolved's stub serves /etc/hosts: on a stock Debian cloud image dig returns 127.0.1.1 for the
        // host's own FQDN. The public resolvers know the real record.
        $stubs = $this->dnsStubs([], localV4: ['203.0.113.7'], publicV4: null);
        file_put_contents($stubs.'/dig', $this->loopbackDig('@1.1.1.1'));

        [$status, $out, $err] = $this->check($stubs);

        self::assertSame(0, $status, $err);
        self::assertStringContainsString('DNS ok', $out);
    }

    public function testLoopbackAnswerStaysAnErrorWhenNoPublicResolverAnswers(): void
    {
        $stubs = $this->dnsStubs([], localV4: ['203.0.113.7'], publicV4: null);
        file_put_contents($stubs.'/dig', $this->loopbackDig('@nobody'));

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('fleet.example.com has the A record 127.0.1.1', $err);
    }

    public function testAPublicResolverThatDisagreesWinsOverTheLoopbackAnswer(): void
    {
        $stubs = $this->dnsStubs([], localV4: ['198.51.100.1'], publicV4: null);
        file_put_contents($stubs.'/dig', $this->loopbackDig('@1.1.1.1'));

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('fleet.example.com has the A record 203.0.113.7', $err);
        self::assertStringNotContainsString('127.0.1.1', $err);
    }

    public function testCnameLinesAreIgnored(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['203.0.113.7']], self::AUTH => ['4' => ['203.0.113.7']]],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(0, $status, $err);
    }

    public function testAFailedLookupIsInconclusiveNotAnAbsentRecord(): void
    {
        // A SERVFAIL on the AAAA query must not read as "no AAAA record": a stale one may sit behind it.
        $stubs = $this->dnsStubs(
            [
                self::DOMAIN => ['4' => ['203.0.113.7'], '6' => 'SERVFAIL'],
                self::AUTH => ['4' => ['203.0.113.7']],
            ],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, $out, $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringNotContainsString('==>', $out);
        self::assertStringContainsString('DNS check inconclusive', $err);
        self::assertStringContainsString('AAAA record of fleet.example.com', $err);
        self::assertStringContainsString('--skip-dns-check', $err);
        self::assertStringNotContainsString('has no A record', $err);
    }

    public function testAFailedARecordLookupIsInconclusiveToo(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => 'SERVFAIL'], self::AUTH => ['4' => ['203.0.113.7']]],
            localV4: ['203.0.113.7'],
            publicV4: null,
        );

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('DNS check inconclusive: the lookup of the A record of fleet.example.com', $err);
    }

    public function testDigThatCannotReachAResolverIsInconclusive(): void
    {
        $stubs = $this->dnsStubs([], localV4: ['203.0.113.7'], publicV4: null);
        file_put_contents($stubs.'/dig', "#!/bin/sh\nexit 9\n");

        [$status, , $err] = $this->check($stubs);

        self::assertSame(2, $status);
        self::assertStringContainsString('DNS check inconclusive', $err);
    }

    public function testSkipFlagBypasses(): void
    {
        $stubs = $this->dnsStubs(
            [self::DOMAIN => ['4' => ['192.0.2.1']], self::AUTH => ['4' => ['192.0.2.1']]],
            localV4: ['203.0.113.7'],
            publicV4: '203.0.113.7',
        );

        [$status, $out] = $this->dryRun(['--domain='.self::DOMAIN, '--skip-dns-check'], [], $stubs);

        self::assertSame(0, $status);
        self::assertStringContainsString('DNS check skipped', $out);
    }

    public function testRunScriptChecksDnsBeforeAnyStep(): void
    {
        $stubs = $this->dnsStubs([], localV4: ['203.0.113.7'], publicV4: null);

        [$status, $out, $err] = $this->bash(
            [self::installDir().'/run.sh', '--dry-run', '--domain='.self::DOMAIN],
            ['FLEET_OS_RELEASE' => $this->osRelease()],
            null,
            $stubs,
        );

        self::assertSame(2, $status);
        self::assertStringNotContainsString('==>', $out);
        self::assertStringContainsString('has no A record', $err);
    }

    /**
     * @param array<string, array<int, list<string>|string>> $records name => family (4 or 6) => addresses, or 'SERVFAIL' for a failed lookup
     * @param list<string>                                   $localV4
     * @param list<string>                                   $localV6
     */
    private function dnsStubs(array $records, array $localV4 = [], array $localV6 = [], ?string $publicV4 = null, ?string $publicV6 = null): string
    {
        $cases = '';
        foreach ($records as $name => $families) {
            foreach ($families as $family => $addresses) {
                $type = 6 === $family ? 'AAAA' : 'A';
                if (\is_string($addresses)) {
                    $cases .= \sprintf("%s:%s) header %s;;\n", $type, $name, $addresses);
                    continue;
                }
                // The answer starts with a CNAME line, like a real chain; the check must only count the A/AAAA lines.
                $lines = \sprintf("header NOERROR; echo '%s. 300 IN CNAME target.example.net.'; ", $name);
                foreach ($addresses as $address) {
                    $lines .= \sprintf("echo 'target.example.net. 300 IN %s %s'; ", $type, $address);
                }
                $cases .= \sprintf("%s:%s) %s;;\n", $type, $name, $lines);
            }
        }
        $ip = static function (array $addresses, string $family): string {
            $out = '';
            foreach ($addresses as $i => $address) {
                $out .= \sprintf("echo '%d: eth0    inet%s %s/64 scope global eth0\\       valid_lft forever preferred_lft forever'\n", $i + 2, '6' === $family ? '6' : '', $address);
            }

            return $out;
        };

        return $this->stubs([
            // dig +noall +comments +answer +time=3 +tries=2 <A|AAAA> <name>
            'dig' => "header() { echo \";; ->>HEADER<<- opcode: QUERY, status: \$1, id: 1\"; echo ';; flags: qr rd ra; QUERY: 1'; echo; }\n".
                "case \"\$6:\$7\" in\n".$cases."*) header NOERROR;;\nesac",
            // /etc/hosts says the name is this host, whatever the resolver answers.
            'getent' => 'echo "203.0.113.7 STREAM $2"',
            'ip' => "case \"\$2\" in\n-4) ".$ip($localV4, '4').";;\n-6) ".$ip($localV6, '6').";;\nesac",
            'curl' => "case \"\$1\" in\n-6) ".(null === $publicV6 ? 'exit 22' : "echo '{$publicV6}'").";;\n*) ".(null === $publicV4 ? 'exit 22' : "echo '{$publicV4}'").";;\nesac",
        ]);
    }

    /**
     * A dig that answers 127.0.1.1 through the system resolver and 203.0.113.7 (A only) through the resolver named
     * $good; any other @resolver fails.
     */
    private function loopbackDig(string $good): string
    {
        return "#!/bin/sh\n".
            "echo ';; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 1'; echo ';; flags: qr rd ra; QUERY: 1'; echo\n".
            "case \"\$8\" in\n".
            "'') ip=127.0.1.1;;\n".
            $good.") ip=203.0.113.7;;\n".
            "*) exit 9;;\nesac\n".
            "[ \"\$6\" = A ] && echo \"\$7. 300 IN A \$ip\"\nexit 0\n";
    }

    /**
     * @return array{int, string, string}
     */
    private function check(string $stubs): array
    {
        return $this->dryRun(['--domain='.self::DOMAIN], [], $stubs);
    }
}
