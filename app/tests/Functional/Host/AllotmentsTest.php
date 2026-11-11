<?php

declare(strict_types=1);

namespace App\Tests\Functional\Host;

use App\Fleet\Audit\AuditLog;
use App\Fleet\Projects\ProjectInput;
use App\Fleet\Projects\ProjectService;
use App\Tests\Support\HostPageTestCase;

/** FLE-318: the Adjust allotments sheet (JSON load, all-or-nothing save). */
final class AllotmentsTest extends HostPageTestCase
{
    public function testSheetLoadsAndSavesInOneBatch(): void
    {
        $service = self::getContainer()->get(ProjectService::class);
        $service->create(new ProjectInput('alpha', 'https://git.example.com/acme/alpha.git', 'develop', cpuThreads: 4, slots: 2));
        $service->create(new ProjectInput('beta', 'https://git.example.com/acme/beta.git', 'develop'));

        $crawler = $this->open('/admin/host?tab=capacity');
        self::assertCount(1, $crawler->filter('[data-testid=allotments-open]'));
        $token = (string) $crawler->filter('[data-controller~=allotments] input[name=_csrf_token]')->attr('value');

        $this->client->request('GET', '/admin/host/allotments');
        self::assertResponseStatusCodeSame(200);
        $json = json_decode($this->body(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertSame(['alpha', 'beta'], array_column($json['projects'], 'name'));
        self::assertSame([4, null], array_column($json['projects'], 'cpu_threads'));
        self::assertArrayHasKey('allocatable_threads', $json);

        // Without the CSRF header nothing is written.
        $this->client->jsonRequest('POST', '/admin/host/allotments', ['projects' => [['name' => 'alpha', 'cpu_threads' => 6, 'slots' => 2]]]);
        self::assertResponseStatusCodeSame(422);

        // One refused project rolls the whole batch back.
        $this->client->jsonRequest('POST', '/admin/host/allotments', ['projects' => [
            ['name' => 'alpha', 'cpu_threads' => 6, 'slots' => 2],
            ['name' => 'beta', 'cpu_threads' => 0, 'slots' => null],
        ]], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(422);
        $errors = json_decode($this->body(), true, 16, \JSON_THROW_ON_ERROR)['errors'];
        self::assertArrayHasKey('beta', $errors);
        self::assertArrayNotHasKey('alpha', $errors);
        self::assertSame(4, $this->cpu('alpha'));

        $this->client->jsonRequest('POST', '/admin/host/allotments', ['projects' => [
            ['name' => 'alpha', 'cpu_threads' => 6, 'slots' => null],
            ['name' => 'beta', 'cpu_threads' => 3, 'slots' => 1],
        ]], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['alpha', 'beta'], json_decode($this->body(), true, 16, \JSON_THROW_ON_ERROR)['saved']);
        self::assertSame([6, 3], [$this->cpu('alpha'), $this->cpu('beta')]);
        self::assertCount(2, self::getContainer()->get(AuditLog::class)->latest(50, AuditLog::PROJECT_CAPACITY));
    }

    public function testMembersAreRefused(): void
    {
        $this->as('member');
        $this->client->request('GET', '/admin/host/allotments');
        self::assertResponseStatusCodeSame(403);
    }

    private function cpu(string $name): ?int
    {
        return self::getContainer()->get(ProjectService::class)->get($name)->cpuThreads();
    }
}
