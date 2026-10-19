<?php

declare(strict_types=1);

namespace App\Tests\Integration\Fleet\Instances;

use App\Domain\Host\HostSettingsRepository;
use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Fleet\Instances\ContextTokens;
use App\Fleet\Instances\InstanceHostnames;
use App\Tests\Support\FleetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContextTokensTest extends KernelTestCase
{
    use FleetDatabase;

    private ContextTokens $tokens;

    protected function setUp(): void
    {
        $host = new HostSettingsRepository($this->setUpFleetDatabase());
        $host->getOrCreate('fleet.example.com');
        $this->tokens = new ContextTokens(new InstanceHostnames($host));
    }

    protected function tearDown(): void
    {
        $this->tearDownFleetDatabase();
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function context(?string $pattern, string $label, string $branch): array
    {
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $project->changeIssueIdRegexp($pattern);

        return $this->tokens->for(new Instance($project, $label, 'full', $branch, 'admin'), $project);
    }

    public function testMatchesV0(): void
    {
        $tokens = $this->context('FLE-[0-9]+', 'fle-12', 'feature/x');

        self::assertSame([
            'instance-id' => 'acme--fle-12',
            'project' => 'acme',
            'instance' => 'fle-12',
            'branch' => 'feature/x',
            'fleet-domain' => 'fleet.example.com',
            'instance-fqdn' => 'acme--fle-12.fleet.example.com',
            'issue-id' => 'FLE-12',
        ], $tokens);
        self::assertSame([
            'FLEET_INSTANCE_ID' => 'acme--fle-12',
            'FLEET_PROJECT' => 'acme',
            'FLEET_INSTANCE' => 'fle-12',
            'FLEET_BRANCH' => 'feature/x',
            'FLEET_INSTANCE_FQDN' => 'acme--fle-12.fleet.example.com',
            'FLEET_DOMAIN' => 'fleet.example.com',
            'FLEET_ISSUE_ID' => 'FLE-12',
        ], $this->tokens->env($tokens));
    }

    public function testNoIssueIdWithoutAPatternOrAMatch(): void
    {
        self::assertArrayNotHasKey('issue-id', $this->context(null, 'main', 'develop'));
        self::assertArrayNotHasKey('issue-id', $this->context('FLE-[0-9]+', 'main', 'develop'));
        self::assertArrayNotHasKey('FLEET_ISSUE_ID', $this->tokens->env($this->context(null, 'main', 'develop')));
    }

    public function testDotDotIssueIdIsDropped(): void
    {
        self::assertArrayNotHasKey('issue-id', $this->context('[.]{2}', 'a', 'x..y'));
        self::assertArrayNotHasKey('issue-id', $this->context('[a-z.]+', '9', 'x..y'));
        self::assertArrayNotHasKey('issue-id', $this->context('[.][a-z]+', 'a', '.hidden'));
        self::assertArrayNotHasKey('issue-id', $this->context('feature/[a-z]+', 'a', 'feature/x'));
    }
}
