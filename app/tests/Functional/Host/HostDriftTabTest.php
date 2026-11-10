<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Domain\Audit\AuditEntryRepository;
use App\Domain\Job\Job;
use App\Fleet\Host\Drift\DriftReport;
use App\Fleet\Host\Drift\DriftReportStore;
use App\Tests\Support\HostPageTestCase;

/** FLE-261: the Drift tab shows the last report and queues the read-only check as a job. */
final class HostDriftTabTest extends HostPageTestCase
{
    private function enable(): void
    {
        $home = $this->paths()->home();
        file_put_contents($home.'/test-apply-helper', "#!/bin/bash\nALLOWED_TARGETS='caddy authelia slices provision drift'\n");
        file_put_contents($home.'/test-site.yml', "---\n");
    }

    private function lastJob(): ?Job
    {
        $job = $this->em()->createQuery(\sprintf('SELECT j FROM %s j ORDER BY j.id DESC', Job::class))->setMaxResults(1)->getOneOrNullResult();

        return $job instanceof Job ? $job : null;
    }

    public function testTheTabSaysSoWhenThisHostCannotCheck(): void
    {
        $crawler = $this->open('/admin/host?tab=drift');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-testid=drift-unavailable]'));
        self::assertNotNull($crawler->filter('[data-testid=drift-run-form] button[type=submit]')->attr('disabled'));
        self::assertCount(1, $crawler->filter('[data-testid=drift-none]'));
        self::assertStringContainsString('firewall rules', $crawler->filter('[data-testid=drift-limits]')->text());
    }

    public function testTheLastReportIsShownPerRole(): void
    {
        $this->enable();
        $report = DriftReport::fromRunnerOutput(DriftReport::BEGIN."\n".json_encode(['schema' => 1, 'roles' => [
            ['role' => 'packages', 'changed' => [], 'drift' => [], 'failed' => []],
            ['role' => 'caddy', 'changed' => [], 'drift' => ['the custom Caddy would be refreshed to 2.11.9 (installed 2.11.8)'], 'failed' => []],
            ['role' => 'units', 'changed' => [['task' => 'Install the unit', 'diff' => "-a\n+b: password: hunter2"]], 'drift' => [], 'failed' => []],
        ]])."\n".DriftReport::END."\n", 'v1.7.0', new \DateTimeImmutable('2026-11-10 12:00:00 UTC'));
        new DriftReportStore($this->paths()->home().'/drift-report.json')->save($report);

        $crawler = $this->open('/admin/host?tab=drift');

        self::assertCount(0, $crawler->filter('[data-testid=drift-unavailable]'));
        self::assertSame('yes', $crawler->filter('[data-testid=drift-report]')->attr('data-drift'));
        self::assertSame(['unchanged', 'changed', 'changed'], $crawler->filter('[data-testid=drift-role]')->each(static fn ($n): string => (string) $n->attr('data-status')));
        self::assertStringContainsString('2.11.9', $crawler->filter('[data-testid=drift-message]')->text());
        self::assertStringContainsString('Install the unit', $crawler->filter('[data-testid=drift-changed]')->text());
        self::assertStringNotContainsString('hunter2', $this->body());
        self::assertStringContainsString('release v1.7.0', $this->body());
    }

    public function testRunningQueuesAReadOnlyJobAndAuditsIt(): void
    {
        $this->enable();

        $this->open('/admin/host?tab=drift');
        $this->client->submitForm('Run drift check');

        $job = $this->lastJob();
        self::assertNotNull($job);
        self::assertSame('drift', $job->type());
        self::assertSame([], $job->params());
        self::assertResponseRedirects('/jobs/'.$job->id(), 303);
        $actions = array_map(static fn ($e): string => $e->action(), new AuditEntryRepository($this->em())->latest(20));
        self::assertContains('host.drift', $actions);
    }

    public function testRunningWithoutTheTokenIsRefused(): void
    {
        $this->enable();

        $this->client->request('POST', '/admin/host/drift');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->lastJob());
    }
}
