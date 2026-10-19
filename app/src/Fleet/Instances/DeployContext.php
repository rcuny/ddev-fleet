<?php

declare(strict_types=1);

namespace App\Fleet\Instances;

use App\Domain\Instance\Instance;
use App\Domain\Project\Project;
use App\Fleet\Jobs\JobLog;
use App\Fleet\Secrets\TokenRenderer;
use App\Fleet\Secrets\TokenRendering;

/**
 * What a DeployStep sees: the instance, its project, the checkout, the context tokens and the job log. A step that
 * writes a file the project must not commit reports it through exclude(), and one that substitutes `[[token]]` in a text
 * does it through render(), the single deploy-time substitution (context tokens, then the project's and fleet-wide secrets).
 */
final class DeployContext
{
    /** @var list<string> */
    private array $excluded = [];

    /**
     * @param array<string, string> $tokens
     */
    public function __construct(
        public readonly Instance $instance,
        public readonly Project $project,
        public readonly string $dir,
        public readonly array $tokens,
        public readonly JobLog $log,
        private readonly TokenRenderer $secrets,
    ) {
    }

    /**
     * Lenient: placeholders that stay unresolved are reported in the result and left as written. Every secret value
     * inserted is registered with the job log masker first.
     *
     * @throws \App\Fleet\Secrets\SecretOpenFailed
     */
    public function render(#[\SensitiveParameter] string $text): TokenRendering
    {
        return $this->secrets->render($text, $this->project->name(), $this->tokens);
    }

    public function exclude(string $relativePath): void
    {
        $this->excluded[] = $relativePath;
    }

    /**
     * @return list<string>
     */
    public function excluded(): array
    {
        return $this->excluded;
    }
}
