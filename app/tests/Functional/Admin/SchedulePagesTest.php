<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Domain\Instance\Instance;
use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Schedules\ScheduleInput;
use App\Fleet\Schedules\ScheduleService;
use App\Tests\Support\AdminTestCase;
use App\Tests\Support\InstanceFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

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
        self::assertCount(0, $page->filter('[data-testid=error-instance]'));
        self::assertSame('not a cron', $page->filter('input[name=cron]')->attr('value'));
        self::assertCount(0, $this->service()->list());
    }

    public function testAMissingTargetIsAcceptedWithAWarningAndABadgeInTheList(): void
    {
        $crawler = $this->open('/admin/schedules/new');
        $this->submit($crawler, 'Create schedule', ['name' => 'later', 'cron' => '0 3 * * *', 'timezone' => 'UTC', 'action' => 'redeploy', 'instance' => 'blog--later']);

        self::assertResponseRedirects('/admin/schedules');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('blog--later does not exist yet; runs are skipped until it does', $page->filter('[data-testid=flashes]')->text());
        self::assertCount(1, $page->filter('[data-testid=schedule-target-missing]'));
    }

    public function testATurboPrefetchDoesNotConsumeTheFlash(): void
    {
        $this->seed();
        $this->post('/admin/schedules/nightly/pause', []);
        self::assertResponseRedirects('/admin/schedules');

        // A hover or a touch on a link prefetches its page before the redirect is followed.
        $prefetch = $this->client->request('GET', self::BASE.'/admin/schedules', server: $this->browserServer() + ['HTTP_X_SEC_PURPOSE' => 'prefetch']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $prefetch->filter('[data-testid=flashes]'));

        $crawler = $this->open('/admin/schedules');
        self::assertStringContainsString('Schedule "nightly" paused.', $crawler->filter('[data-testid=flashes]')->text());
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
        $lastJob = $crawler->filter('[data-testid=schedule-last-job]');
        self::assertCount(1, $lastJob);
        self::assertMatchesRegularExpression('#^/jobs/\d+$#', (string) $lastJob->attr('href'));

        $crawler = $this->open('/admin/schedules/nightly/history');
        self::assertResponseStatusCodeSame(200);
        $rows = $crawler->filter('[data-testid=schedule-run-row]');
        self::assertCount(1, $rows);
        self::assertSame('manual', trim($rows->filter('[data-testid=run-trigger]')->text()));
        self::assertMatchesRegularExpression('/^#\d+$/', trim($rows->filter('[data-testid=run-job]')->text()));
        self::assertMatchesRegularExpression('#^/jobs/\d+$#', (string) $rows->filter('[data-testid=run-job-link]')->attr('href'));
    }

    private const string CRON_EMPTY = 'Enter a cron expression with five fields, for example "0 3 * * *".';
    private const string CRON_BAD = 'Not a valid cron expression: use five fields (minute hour day-of-month month day-of-week), for example "0 3 * * *".';
    private const string ZONE_BAD = 'Enter an IANA timezone such as "Europe/Brussels" or "UTC".';
    private const string NAME_SHAPE = 'Use lowercase letters, digits and single dashes only, starting and ending with a letter or digit.';

    /**
     * @return iterable<string, array{array<string, string>, string, string}> overrides, the field that speaks, its message
     */
    public static function refusals(): iterable
    {
        yield 'name empty' => [['name' => ''], 'name', 'Enter a name.'];
        yield 'name uppercase' => [['name' => 'Nightly'], 'name', self::NAME_SHAPE];
        yield 'name underscore' => [['name' => 'night_ly'], 'name', self::NAME_SHAPE];
        yield 'name leading dash' => [['name' => '-night'], 'name', self::NAME_SHAPE];
        yield 'name trailing dash' => [['name' => 'night-'], 'name', self::NAME_SHAPE];
        yield 'name double dash' => [['name' => 'night--ly'], 'name', 'The name must not contain two dashes in a row ("--" separates the project from the instance label).'];
        yield 'name reserved' => [['name' => 'new'], 'name', 'This name is reserved.'];
        yield 'name too long' => [['name' => str_repeat('n', 41)], 'name', 'The name must be at most 40 characters.'];
        yield 'name taken' => [['name' => 'taken'], 'name', 'A schedule with this name already exists.'];
        yield 'cron blank' => [['cron' => '   '], 'cron', self::CRON_EMPTY];
        yield 'cron four fields' => [['cron' => '0 3 * *'], 'cron', self::CRON_BAD];
        yield 'cron six fields' => [['cron' => '0 3 * * * *'], 'cron', self::CRON_BAD];
        yield 'cron alias' => [['cron' => '@daily'], 'cron', self::CRON_BAD];
        yield 'cron minute 61' => [['cron' => '61 * * * *'], 'cron', self::CRON_BAD];
        yield 'cron hour 25' => [['cron' => '* 25 * * *'], 'cron', self::CRON_BAD];
        yield 'cron day 32' => [['cron' => '* * 32 * *'], 'cron', self::CRON_BAD];
        yield 'cron month 13' => [['cron' => '* * * 13 *'], 'cron', self::CRON_BAD];
        yield 'cron weekday 8' => [['cron' => '* * * * 8'], 'cron', self::CRON_BAD];
        yield 'cron words' => [['cron' => 'not a cron'], 'cron', self::CRON_BAD];
        yield 'cron over 100 characters' => [['cron' => implode(',', range(0, 39)).' * * * *'], 'cron', self::CRON_BAD];
        yield 'timezone unknown' => [['timezone' => 'Mars/Base'], 'timezone', self::ZONE_BAD];
        yield 'timezone wrong case' => [['timezone' => 'europe/paris'], 'timezone', self::ZONE_BAD];
        yield 'timezone no such city' => [['timezone' => 'Europe/Atlantis'], 'timezone', self::ZONE_BAD];
        yield 'timezone blank' => [['timezone' => '   '], 'timezone', self::ZONE_BAD];
        yield 'instance empty' => [['instance' => ''], 'instance', 'Enter the id of the instance to redeploy.'];
        yield 'instance blank' => [['instance' => '   '], 'instance', 'Enter the id of the instance to redeploy.'];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('refusals')]
    public function testEachBadValueSpeaksOnItsOwnFieldAndKeepsWhatWasTyped(array $override, string $field, string $message): void
    {
        $this->seed('taken');
        $values = $override + ['name' => 'fresh', 'cron' => '0 3 * * *', 'timezone' => 'UTC', 'action' => 'redeploy', 'instance' => 'blog--i1'];

        $crawler = $this->open('/admin/schedules/new');
        $this->submit($crawler, 'Create schedule', $values);

        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getCrawler();
        self::assertSame($message, trim($page->filter('[data-testid=error-'.$field.']')->text()));
        self::assertCount(1, $page->filter('[aria-invalid=true]'), 'only the bad field is marked');
        self::assertSame('schedule-'.$field.'-error', $page->filter('[aria-invalid=true]')->attr('aria-describedby'));
        self::assertSame($override[$field], $page->filter('input[name='.$field.']')->attr('value'));
        self::assertCount(1, $this->service()->list(), 'nothing was created');
    }

    public function testAnEmptyFormNamesEveryRequiredField(): void
    {
        $crawler = $this->open('/admin/schedules/new');
        self::assertSame('UTC', $crawler->filter('input[name=timezone]')->attr('value'));
        self::assertCount(0, $crawler->filter('[aria-invalid=true]'));
        self::assertSame(['redeploy'], $crawler->filter('select[name=action] option')->extract(['_text']));
        self::assertGreaterThan(300, $crawler->filter('#schedule-timezones option')->count());
        self::assertCount(1, $crawler->filter('#schedule-timezones option[value="Europe/Paris"]'));
        self::assertSame('64', $crawler->filter('input[name=name]')->attr('maxlength'));

        $this->submit($crawler, 'Create schedule', ['name' => '', 'cron' => '', 'timezone' => '', 'action' => 'redeploy', 'instance' => '']);

        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getCrawler();
        self::assertSame('Enter a name.', trim($page->filter('[data-testid=error-name]')->text()));
        self::assertSame(self::CRON_EMPTY, trim($page->filter('[data-testid=error-cron]')->text()));
        self::assertSame(self::ZONE_BAD, trim($page->filter('[data-testid=error-timezone]')->text()));
        self::assertSame('Enter the id of the instance to redeploy.', trim($page->filter('[data-testid=error-instance]')->text()));
        self::assertCount(4, $page->filter('[aria-invalid=true]'));
        self::assertSame('schedule-name-error', $page->filter('input[name=name]')->attr('aria-describedby'));
    }

    public function testBlanksAroundTheValuesAreTrimmedOnSave(): void
    {
        $crawler = $this->open('/admin/schedules/new');
        $this->submit($crawler, 'Create schedule', ['name' => '  padded  ', 'cron' => '  0 3 * * *  ', 'timezone' => ' UTC ', 'action' => 'redeploy', 'instance' => ' blog--i1 ']);

        self::assertResponseRedirects('/admin/schedules');
        $schedule = $this->service()->get('padded');
        self::assertSame('0 3 * * *', $schedule->cron());
        self::assertSame('UTC', $schedule->timezone());
        self::assertSame('blog--i1', $schedule->instanceId());
    }

    public function testAMissingTargetSkipsTheRunWithAReasonUntilTheInstanceExists(): void
    {
        $crawler = $this->open('/admin/schedules/new');
        $this->submit($crawler, 'Create schedule', ['name' => 'later', 'cron' => '0 3 * * *', 'timezone' => 'UTC', 'action' => 'redeploy', 'instance' => 'blog--later']);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Schedule "later" created.', $page->filter('[data-testid=flashes]')->text());
        self::assertSame('Target missing', trim($page->filter('[data-testid=schedule-target-missing]')->text()));

        $this->post('/admin/schedules/later/run', []);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Schedule "later" did not run: Instance blog--later does not exist yet', $page->filter('[data-testid=flashes]')->text());
        self::assertSame('skipped', trim($page->filter('[data-testid=schedule-last]')->text()));
        self::assertCount(0, $page->filter('[data-testid=schedule-last-job]'));

        $history = $this->open('/admin/schedules/later/history');
        self::assertCount(1, $history->filter('[data-testid=schedule-run-row]'));
        self::assertSame('skipped', trim($history->filter('[data-testid=run-result]')->text()));
        self::assertSame('-', trim($history->filter('[data-testid=run-job]')->text()));
        self::assertCount(0, $history->filter('[data-testid=run-job-link]'));
        self::assertNotSame('', trim($history->filter('[data-testid=schedule-run-row] td')->last()->text()));

        new InstanceRepository($this->em)->createMany([new Instance($this->projectNamed('blog'), 'later', 'full', 'develop', 'admin')]);
        $page = $this->open('/admin/schedules');
        self::assertCount(0, $page->filter('[data-testid=schedule-target-missing]'));
        $this->post('/admin/schedules/later/run', []);
        $page = $this->client->followRedirect();
        self::assertMatchesRegularExpression('/Schedule "later" started job #\d+/', $page->filter('[data-testid=flashes]')->text());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nextRuns(): iterable
    {
        // 02:30 in India (UTC+5:30, no daylight saving) is 21:00 UTC of the previous day.
        yield 'Kolkata' => ['30 2 * * *', 'Asia/Kolkata', '/^\d{4}-\d{2}-\d{2} 21:00 UTC$/'];
        yield 'step, range, weekday names' => ['*/15 8-18 * * MON-FRI', 'UTC', '/^\d{4}-\d{2}-\d{2} (0[89]|1[0-8]):(00|15|30|45) UTC$/'];
        yield 'leap day in Auckland' => ['0 0 29 2 *', 'Pacific/Auckland', '/^\d{4}-(02-28|02-29|03-01) 1[0-2]:00 UTC$/'];
    }

    #[DataProvider('nextRuns')]
    public function testANewScheduleShowsItsNextRunFollowingTheCronAndTheTimezone(string $cron, string $timezone, string $pattern): void
    {
        $crawler = $this->open('/admin/schedules/new');
        $this->submit($crawler, 'Create schedule', ['name' => 'timed', 'cron' => $cron, 'timezone' => $timezone, 'action' => 'redeploy', 'instance' => 'blog--i1']);
        $page = $this->client->followRedirect();

        $row = $page->filter('[data-testid=schedule-row]');
        self::assertCount(1, $row);
        self::assertSame('Active', trim($row->filter('[data-testid=schedule-state]')->text()));
        self::assertSame($cron, $row->filter('[data-testid=schedule-cron]')->text());
        self::assertStringContainsString($timezone.' · redeploy · blog--i1', preg_replace('/\s+/', ' ', $row->text()) ?? '');
        self::assertMatchesRegularExpression($pattern, trim($row->filter('[data-testid=schedule-next]')->text()));
        self::assertSame('never run', trim($row->filter('[data-testid=schedule-last]')->text()));
        self::assertCount(0, $row->filter('[data-testid=schedule-last-job]'));
        self::assertCount(0, $row->filter('[data-testid=schedule-resume]'));
        foreach (['pause', 'run', 'edit', 'history', 'delete'] as $control) {
            self::assertCount(1, $row->filter('[data-testid=schedule-'.$control.']'), $control);
        }
    }

    public function testEditPrefillsRefusesKeepsTheNameAndKeepsAPausedScheduleDormant(): void
    {
        $this->seed();
        new InstanceRepository($this->em)->createMany([new Instance($this->projectNamed('blog'), 'other', 'full', 'develop', 'admin')]);

        $crawler = $this->open('/admin/schedules/nightly/edit');
        self::assertSame('Edit nightly', trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('Edit nightly', $crawler->filter('title')->text());
        self::assertCount(0, $crawler->filter('input[name=name]'));
        self::assertSame('0 3 * * *', $crawler->filter('input[name=cron]')->attr('value'));
        self::assertSame('Europe/Paris', $crawler->filter('input[name=timezone]')->attr('value'));
        self::assertSame('blog--i1', $crawler->filter('input[name=instance]')->attr('value'));
        self::assertSame('Save schedule', trim($crawler->filter('[data-testid=schedule-save]')->text()));

        $this->submit($crawler, 'Save schedule', ['cron' => 'nope', 'timezone' => 'Mars/Base', 'instance' => '']);
        self::assertResponseStatusCodeSame(422);
        $page = $this->client->getCrawler();
        self::assertSame('Edit nightly', trim($page->filter('h1')->text()));
        self::assertSame(self::CRON_BAD, trim($page->filter('[data-testid=error-cron]')->text()));
        self::assertSame(self::ZONE_BAD, trim($page->filter('[data-testid=error-timezone]')->text()));
        self::assertSame('Enter the id of the instance to redeploy.', trim($page->filter('[data-testid=error-instance]')->text()));
        self::assertCount(0, $page->filter('[data-testid=error-name]'));
        self::assertSame('nope', $page->filter('input[name=cron]')->attr('value'));
        self::assertSame('0 3 * * *', $this->service()->get('nightly')->cron(), 'a refusal changes nothing');

        // Saving without a change is accepted.
        $this->submit($this->open('/admin/schedules/nightly/edit'), 'Save schedule', []);
        self::assertResponseRedirects('/admin/schedules');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Schedule "nightly" saved.', $page->filter('[data-testid=flashes]')->text());

        // Every field changes on a paused schedule: it stays paused with no next run, and resuming follows the new timing.
        $this->post('/admin/schedules/nightly/pause', []);
        $this->submit($this->open('/admin/schedules/nightly/edit'), 'Save schedule', ['cron' => '30 2 * * *', 'timezone' => 'Asia/Kolkata', 'instance' => 'blog--other']);
        $page = $this->client->followRedirect();
        $row = $page->filter('[data-testid=schedule-row]');
        self::assertSame('30 2 * * *', $row->filter('[data-testid=schedule-cron]')->text());
        self::assertStringContainsString('Asia/Kolkata · redeploy · blog--other', preg_replace('/\s+/', ' ', $row->text()) ?? '');
        self::assertSame('Paused', trim($row->filter('[data-testid=schedule-state]')->text()));
        self::assertStringContainsString('none', $row->filter('[data-testid=schedule-next]')->text());

        $this->post('/admin/schedules/nightly/resume', []);
        $page = $this->client->followRedirect();
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 21:00 UTC$/', trim($page->filter('[data-testid=schedule-next]')->text()));
    }

    public function testRunNowWorksWhilePausedKeepsNewestHistoryFirstAndLateTabsAreHarmless(): void
    {
        $this->seed();
        $this->service()->create(new ScheduleInput('orphan', '0 4 * * *', 'UTC', 'redeploy', 'blog--i1'), 'admin');

        $this->post('/admin/schedules/nightly/pause', []);
        $page = $this->client->followRedirect();
        self::assertSame('none (paused)', trim($page->filter('[data-name=nightly] [data-testid=schedule-next]')->text()));
        self::assertCount(0, $page->filter('[data-name=nightly] [data-testid=schedule-pause]'));

        // Run now works while paused and leaves it paused; twice, so the history has two rows.
        $this->post('/admin/schedules/nightly/run', []);
        $page = $this->client->followRedirect();
        self::assertMatchesRegularExpression('/Schedule "nightly" started job #\d+\./', $page->filter('[data-testid=flashes]')->text());
        self::assertSame('Paused', trim($page->filter('[data-name=nightly] [data-testid=schedule-state]')->text()));
        $this->post('/admin/schedules/nightly/run', []);
        $page = $this->client->followRedirect();
        $href = (string) $page->filter('[data-name=nightly] [data-testid=schedule-last-job]')->attr('href');
        $lastResult = trim($page->filter('[data-name=nightly] [data-testid=schedule-last]')->text());
        self::assertMatchesRegularExpression('/^(claimed|started|succeeded|failed)$/', $lastResult);

        $history = $this->open('/admin/schedules/nightly/history');
        $runs = $history->filter('[data-testid=schedule-run-row]');
        self::assertCount(2, $runs);
        self::assertSame(['manual', 'manual'], $history->filter('[data-testid=run-trigger]')->extract(['_text']));
        self::assertSame($lastResult, trim($runs->first()->filter('[data-testid=run-result]')->text()));
        $jobs = array_map(static fn (string $t): int => (int) ltrim(trim($t), '#'), $history->filter('[data-testid=run-job]')->extract(['_text']));
        self::assertGreaterThan($jobs[1], $jobs[0], 'newest first');
        self::assertSame($href, $runs->first()->filter('[data-testid=run-job-link]')->attr('href'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', trim($runs->first()->filter('td')->first()->text()));

        // Resume and pause a second time (a stale tab): harmless, same flash.
        foreach (['resume', 'resume', 'pause', 'pause'] as $action) {
            $this->post('/admin/schedules/nightly/'.$action, []);
            self::assertResponseRedirects('/admin/schedules', null, $action);
            $page = $this->client->followRedirect();
            self::assertStringContainsString('Schedule "nightly" '.('resume' === $action ? 'resumed' : 'paused').'.', $page->filter('[data-testid=flashes]')->text());
        }

        // The instance of a schedule is gone: Run now says so and the history keeps the reason.
        $this->em()->getConnection()->executeStatement('DELETE FROM instance');
        $this->post('/admin/schedules/orphan/run', []);
        $page = $this->client->followRedirect();
        self::assertMatchesRegularExpression('/Schedule "orphan" did not run: .+/', $page->filter('[data-testid=flashes]')->text());
        self::assertSame('skipped', trim($page->filter('[data-name=orphan] [data-testid=schedule-last]')->text()));
        self::assertCount(0, $page->filter('[data-name=orphan] [data-testid=schedule-last-job]'));
        $history = $this->open('/admin/schedules/orphan/history');
        self::assertCount(1, $history->filter('[data-testid=schedule-run-row]'));
        self::assertSame('skipped', trim($history->filter('[data-testid=run-result]')->text()));
        self::assertSame('-', trim($history->filter('[data-testid=run-job]')->text()));
        self::assertCount(0, $history->filter('[data-testid=run-job-link]'));
    }

    public function testTheFormListsTheRegisteredActions(): void
    {
        $crawler = $this->open('/admin/schedules/new');

        self::assertSame('Available actions: redeploy.', trim($crawler->filter('[data-testid=schedule-actions]')->text()));
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
        foreach (['pause', 'resume', 'run', 'delete'] as $action) {
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
