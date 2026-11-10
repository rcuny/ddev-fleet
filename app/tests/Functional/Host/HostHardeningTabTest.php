<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Job\Job;
use App\Domain\Job\JobStatus;
use App\Fleet\Shell\CommandResult;
use App\Fleet\Shell\RecordingCommandRunner;
use App\Tests\Support\HostPageTestCase;
use App\Tests\Support\ReauthLogin;

/**
 * The Hardening tab (FLE-260): the optional role's answers, the password re-auth, the job with its log, and the
 * firewall dead-man's switch state, which the page reports but never confirms.
 */
final class HostHardeningTabTest extends HostPageTestCase
{
    use ReauthLogin;

    private function enableProvision(): void
    {
        $home = $this->paths()->home();
        file_put_contents($home.'/test-apply-helper', "#!/bin/bash\nALLOWED_TARGETS='caddy authelia slices provision'\n");
        file_put_contents($home.'/test-site.yml', "---\n");
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function values(array $override = []): array
    {
        return $override + [
            'host_hardening[enabled]' => '1',
            'host_hardening[sshUsers]' => "admin\ndeploy",
            'host_hardening[invokingUser]' => 'admin',
            'host_hardening[trustedIps]' => "203.0.113.10\n2001:db8::/32",
            'host_hardening[graceMinutes]' => '15',
        ];
    }

    private function runner(): RecordingCommandRunner
    {
        $runner = self::getContainer()->get('fleet.test_alias.recording_command_runner');
        self::assertInstanceOf(RecordingCommandRunner::class, $runner);

        return $runner;
    }

    private function lastJob(): ?Job
    {
        $job = $this->em()->createQuery(\sprintf('SELECT j FROM %s j ORDER BY j.id DESC', Job::class))->setMaxResults(1)->getOneOrNullResult();

        return $job instanceof Job ? $job : null;
    }

    public function testTheTabSaysSoWhenThisHostCannotApplyOptionalRoles(): void
    {
        $crawler = $this->open('/admin/host?tab=hardening');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=hardening-unavailable]'));
        self::assertNotNull($crawler->filter('[data-testid=host-hardening-form] button[type=submit]')->attr('disabled'));
    }

    public function testTheFormIsFilledFromTheHostAnswersAndTheFirewallIsReported(): void
    {
        $this->enableProvision();
        $home = $this->paths()->home();
        file_put_contents($home.'/test-host-vars.yml', "---\nfleet_domain: \"fleet.example.com\"\nfleet_acme_email: \"\"\nfleet_shell_profile: true\nfleet_hardening_enabled: true\nfleet_hardening_ssh_allow_users:\n  - \"admin\"\nfleet_hardening_trusted_ips:\n  - \"203.0.113.10\"\nfleet_hardening_firewall_grace_minutes: 20\nfleet_hardening_tmp: false\n");
        file_put_contents($home.'/test-hardening.conf', "ENABLED=1\n");

        $this->runner()->expect(['systemctl', 'is-active'], new CommandResult(3));
        $crawler = $this->open('/admin/host?tab=hardening');

        self::assertCount(0, $crawler->filter('[data-testid=hardening-unavailable]'));
        self::assertSame('admin', trim($crawler->filter('textarea[name="host_hardening[sshUsers]"]')->text()));
        self::assertSame('20', $crawler->filter('input[name="host_hardening[graceMinutes]"]')->attr('value'));
        // Enabled, no marker, and the timer is not running: the grace period is over.
        self::assertSame('expired', $crawler->filter('[data-testid=hardening-firewall-state]')->attr('data-state'));

        file_put_contents($home.'/test-firewall-confirmed', '');
        $crawler = $this->open('/admin/host?tab=hardening');
        self::assertSame('confirmed', $crawler->filter('[data-testid=hardening-firewall-state]')->attr('data-state'));
        self::assertCount(0, $crawler->filter('[data-testid=hardening-confirm-hint]'));
    }

    public function testSavingNeedsTheRecentPasswordAndQueuesNothingWithout(): void
    {
        $this->enableProvision();

        $this->submit('hardening', 'Save and apply', $this->values());

        self::assertResponseRedirects('/admin/reauth?next=/admin/host', 303);
        self::assertNull($this->lastJob());
    }

    public function testSavingQueuesAProvisionJobWithTheAnswersAndAuditsIt(): void
    {
        $this->enableProvision();
        $this->confirmPasswordOnHostPage();

        $this->submit('hardening', 'Save and apply', $this->values());

        $job = $this->lastJob();
        self::assertNotNull($job);
        self::assertSame('provision', $job->type());
        self::assertNull($job->instanceId());
        self::assertResponseRedirects('/jobs/'.$job->id(), 303);
        self::assertSame(['admin', 'deploy'], $job->params()['sshAllowUsers']);
        self::assertSame(['203.0.113.10', '2001:db8::/32'], $job->params()['trustedIps']);
        self::assertSame(15, $job->params()['graceMinutes']);
        self::assertSame('admin', $job->params()['invokingUser']);
        // The test transport runs the job at once; the recording runner answers the helper call with success.
        self::assertContains($this->em()->find(Job::class, $job->id())?->status(), [JobStatus::Succeeded, JobStatus::Queued]);

        $actions = array_map(static fn ($e): string => $e->action(), new AuditEntryRepository($this->em())->latest(20));
        self::assertContains('host.provision', $actions);
    }

    public function testTheAccountYouLogInWithMustBeInTheAllowListSoYouCannotLockYourselfOut(): void
    {
        $this->enableProvision();
        $this->confirmPasswordOnHostPage();

        $this->submit('hardening', 'Save and apply', $this->values(['host_hardening[invokingUser]' => 'someone']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('must be in the allow list', $this->body());
        self::assertNull($this->lastJob());
    }

    public function testBadAddressesAndGraceAreRefusedWithoutAJob(): void
    {
        $this->enableProvision();
        $this->confirmPasswordOnHostPage();

        $this->submit('hardening', 'Save and apply', $this->values(['host_hardening[trustedIps]' => "300.1.1.1\nnot-an-ip", 'host_hardening[graceMinutes]' => '1']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('IP addresses or CIDR ranges', $this->body());
        self::assertStringContainsString('2 to 120 minutes', $this->body());
        self::assertNull($this->lastJob());
    }

    public function testAMemberCannotSeeTheTab(): void
    {
        $this->as('member');
        $this->open('/admin/host?tab=hardening');

        self::assertResponseStatusCodeSame(403);
    }
}
