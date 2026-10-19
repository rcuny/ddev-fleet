<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Instance\InstanceState;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleService;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\InstanceFixtures;
use Doctrine\ORM\EntityManagerInterface;

final class SchedulePagesTest extends AdminTestCase
{
    use InstanceFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->em();
        $this->instancesOf($this->projectNamed('blog'), [InstanceState::Running]);
    }

    private function service(): ScheduleService
    {
        $service = $this->container()->get(ScheduleService::class);
        self::assertInstanceOf(ScheduleService::class, $service);

        return $service;
    }

    private function seed(string $name = 'nightly'): void
    {
        $this->service()->create(new ScheduleInput($name, '0 3 * * *', 'Europe/Paris', 'redeploy', 'blog--i1'), 'admin');
    }

    public function testEmptyList(): void
    {
        $crawler = $this->open('/admin/schedules');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('[data-testid=schedules-empty]'));
        self::assertCount(0, $crawler->filter('[data-testid=schedule-row]'));
    }

    public function testCreateListEditDelete(): void
    {
        $crawler = $this->open('/admin/schedules/new');
        self::assertResponseStatusCodeSame(200);
        $this->submit($crawler, 'Create schedule', ['name' => 'nightly', 'cron' => '0 3 * * *', 'timezone' => 'Europe/Paris', 'action' => 'redeploy', 'instance' => 'blog--i1']);
        self::assertResponseRedirects('/admin/schedules');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Schedule "nightly" created.', $crawler->filter('[data-testid=flashes]')->text());
        $row = $crawler->filter('[data-testid=schedule-row]');
        self::assertCount(1, $row);
        self::assertStringContainsString('nightly', $row->filter('[data-testid=schedule-name]')->text());
        self::assertSame('0 3 * * *', $row->filter('[data-testid=schedule-cron]')->text());
        self::assertStringContainsString('UTC', $row->filter('[data-testid=schedule-next]')->text());
        self::assertSame('never run', $row->filter('[data-testid=schedule-last]')->text());

        $crawler = $this->open('/admin/schedules/nightly/edit');
        self::assertCount(0, $crawler->filter('input[name=name]'));
        $this->submit($crawler, 'Save schedule', ['cron' => '30 4 * * *']);
        self::assertResponseRedirects('/admin/schedules');
        self::assertSame('30 4 * * *', $this->service()->get('nightly')->cron());

        $this->post('/admin/schedules/nightly/delete', []);
        self::assertResponseRedirects('/admin/schedules');
        self::assertCount(0, $this->service()->list());
    }

    public function testValidationErrorsAreShownNextToTheFields(): void
    {
        $crawler = $this->open('/admin/schedules/new');
        $this->submit($crawler, 'Create schedule', ['name' => 'nightly', 'cron' => 'not a cron', 'timezone' => 'Mars/Base', 'action' => 'redeploy', 'instance' => 'nope--x']);

        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getCrawler();
        self::assertCount(1, $page->filter('[data-testid=error-cron]'));
        self::assertCount(1, $page->filter('[data-testid=error-timezone]'));
        self::assertCount(1, $page->filter('[data-testid=error-instance]'));
        self::assertSame('not a cron', $page->filter('input[name=cron]')->attr('value'));
        self::assertCount(0, $this->service()->list());
    }

    public function testPauseResumeAndRunNow(): void
    {
        $this->seed();

        $this->post('/admin/schedules/nightly/pause', []);
        self::assertResponseRedirects('/admin/schedules');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Schedule "nightly" paused.', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('Paused', trim($crawler->filter('[data-testid=schedule-state]')->text()));
        self::assertCount(1, $crawler->filter('[data-testid=schedule-resume]'));
        self::assertStringContainsString('none', $crawler->filter('[data-testid=schedule-next]')->text());

        $this->post('/admin/schedules/nightly/resume', []);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('resumed', $crawler->filter('[data-testid=flashes]')->text());
        self::assertSame('Active', trim($crawler->filter('[data-testid=schedule-state]')->text()));

        $this->post('/admin/schedules/nightly/run', []);
        self::assertResponseRedirects('/admin/schedules');
        $crawler = $this->client->followRedirect();
        self::assertMatchesRegularExpression('/started job #\d+/', $crawler->filter('[data-testid=flashes]')->text());

        $crawler = $this->open('/admin/schedules/nightly/history');
        self::assertResponseStatusCodeSame(200);
        $rows = $crawler->filter('[data-testid=schedule-run-row]');
        self::assertCount(1, $rows);
        self::assertSame('manual', trim($rows->filter('[data-testid=run-trigger]')->text()));
        self::assertMatchesRegularExpression('/^#\d+$/', trim($rows->filter('[data-testid=run-job]')->text()));
    }

    public function testRunNowOfAVanishedInstanceFlashesTheFailure(): void
    {
        $this->service()->create(new ScheduleInput('ghost', '0 3 * * *', 'UTC', 'redeploy', 'blog--i1'), 'admin');
        $this->em()->getConnection()->executeStatement('DELETE FROM instance');

        $this->post('/admin/schedules/ghost/run', []);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('did not run', $crawler->filter('[data-testid=flashes]')->text());
    }

    public function testHistoryOfAnUnrunScheduleIsEmpty(): void
    {
        $this->seed();
        $crawler = $this->open('/admin/schedules/nightly/history');

        self::assertCount(1, $crawler->filter('[data-testid=schedule-history-empty]'));
    }

    public function testUnknownNameIs404(): void
    {
        foreach (['/admin/schedules/nope/edit', '/admin/schedules/nope/history'] as $path) {
            $this->open($path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        foreach (['pause', 'resume', 'run', 'delete'] as $action) {
            $this->post('/admin/schedules/nope/'.$action, []);
            self::assertResponseStatusCodeSame(404, $action);
        }
    }

    public function testPostsRefuseABadCsrfToken(): void
    {
        $this->seed();

        foreach (['pause', 'resume', 'run', 'delete', 'edit'] as $action) {
            $this->client->request('POST', self::BASE.'/admin/schedules/nightly/'.$action, ['_token' => 'x'], [], $this->browserServer());
            self::assertResponseStatusCodeSame(403, $action);
        }
        $this->client->request('POST', self::BASE.'/admin/schedules/new', ['_token' => 'x', 'name' => 'other', 'cron' => '@daily', 'timezone' => 'UTC', 'action' => 'redeploy', 'instance' => 'blog--i1'], [], $this->browserServer());
        self::assertResponseStatusCodeSame(403);

        $schedules = $this->service()->list();
        self::assertCount(1, $schedules);
        self::assertTrue($schedules[0]->isEnabled());
    }

    public function testMemberIsRefusedAndAnonymousIsNotServed(): void
    {
        $this->seed();
        $paths = ['/admin/schedules', '/admin/schedules/new', '/admin/schedules/nightly/edit', '/admin/schedules/nightly/history'];

        $this->as('member');
        foreach ($paths as $path) {
            $this->open($path);
            self::assertResponseStatusCodeSame(403, $path);
        }
        foreach (['pause', 'run', 'delete'] as $action) {
            $this->post('/admin/schedules/nightly/'.$action, []);
            self::assertResponseStatusCodeSame(403, $action);
        }
        self::assertTrue($this->service()->get('nightly')->isEnabled());

        $this->as('none');
        foreach ($paths as $path) {
            $this->open($path);
            self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), $path);
        }
    }
}
