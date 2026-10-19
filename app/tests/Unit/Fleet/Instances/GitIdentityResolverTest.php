<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Fleet\Instances\GitIdentityResolver;
use App\Fleet\Instances\HostGitIdentity;
use PHPUnit\Framework\TestCase;

final class GitIdentityResolverTest extends TestCase
{
    private function resolver(): GitIdentityResolver
    {
        return new GitIdentityResolver(new class implements HostGitIdentity {
            public function default(): array
            {
                return ['name' => 'ddev-fleet bot', 'email' => 'bot@fleet.example.com'];
            }
        });
    }

    private function project(GitBotMode $mode, ?string $name = null, ?string $email = null): Project
    {
        $project = new Project('acme', 'https://git.example.com/acme/blog.git');
        $project->changeGitBot($mode, $name, $email);

        return $project;
    }

    public function testInheritUsesTheHostDefault(): void
    {
        self::assertSame(
            ['name' => 'ddev-fleet bot', 'email' => 'bot@fleet.example.com'],
            $this->resolver()->forProject($this->project(GitBotMode::Inherit)),
        );
    }

    public function testOverrideUsesTheProjectValuesAndABlankOneFallsBack(): void
    {
        self::assertSame(
            ['name' => 'Acme CI', 'email' => 'ci@acme.example.com'],
            $this->resolver()->forProject($this->project(GitBotMode::Override, 'Acme CI', 'ci@acme.example.com')),
        );
        self::assertSame(
            ['name' => 'Acme CI', 'email' => 'bot@fleet.example.com'],
            $this->resolver()->forProject($this->project(GitBotMode::Override, 'Acme CI', '  ')),
        );
        self::assertSame(
            ['name' => 'ddev-fleet bot', 'email' => 'bot@fleet.example.com'],
            $this->resolver()->forProject($this->project(GitBotMode::Override, null, null)),
        );
    }

    public function testDisabledIsNull(): void
    {
        self::assertNull($this->resolver()->forProject($this->project(GitBotMode::Disabled, 'x', 'y@example.com')));
    }
}
