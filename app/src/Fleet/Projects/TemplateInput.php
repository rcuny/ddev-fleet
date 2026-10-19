<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

use App\Domain\Template\Template;

/**
 * What a form or a command line submits for a template. The command lists keep their order (and duplicates).
 */
final readonly class TemplateInput
{
    /**
     * @param list<string> $postDeploy command lines run with `ddev exec` after a deploy
     * @param list<string> $tty1       command lines typed into the first instance terminal
     * @param list<string> $tty2       command lines typed into the second instance terminal
     */
    public function __construct(
        public string $name,
        public ?string $drupalEnv = null,
        public array $postDeploy = [],
        public array $tty1 = [],
        public array $tty2 = [],
    ) {
    }

    public static function fromTemplate(Template $template): self
    {
        return new self(
            $template->name(),
            $template->drupalEnv(),
            $template->postDeploy(),
            $template->tty1(),
            $template->tty2(),
        );
    }

    /**
     * @param array<string, mixed> $changes new values by property name
     */
    public function with(array $changes): self
    {
        $unknown = array_diff(array_keys($changes), array_keys(get_object_vars($this)));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('Unknown template input field(s): %s.', implode(', ', $unknown)));
        }

        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * Trims, turns a blank Drupal environment into null and drops blank command lines.
     */
    public function normalised(): self
    {
        $env = trim($this->drupalEnv ?? '');

        return new self(
            trim($this->name),
            '' === $env ? null : $env,
            self::lines($this->postDeploy),
            self::lines($this->tty1),
            self::lines($this->tty2),
        );
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function lines(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            $line = trim($line, " \t");
            if ('' !== $line) {
                $kept[] = $line;
            }
        }

        return $kept;
    }
}
