<?php

declare(strict_types=1);

namespace App\Command\Template;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Command\Support\Shapes;
use App\Fleet\Projects\TemplateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:template:show', description: 'Show one template')]
final class TemplateShowCommand extends Command
{
    public function __construct(private readonly TemplateService $templates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project name');
        $this->addArgument('name', InputArgument::REQUIRED, 'Template name');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::template());

        return $out->run(function () use ($input, $out): void {
            $template = $this->templates->get((string) $input->getArgument('project'), (string) $input->getArgument('name'));
            $out->emit(Shapes::template($template), static function (JsonOutput $out) use ($template): void {
                $out->pairs([
                    'project' => $template->project()->name(),
                    'name' => $template->name(),
                    'drupal env' => $template->drupalEnv(),
                    'post-deploy' => $template->postDeploy(),
                    'tty1' => $template->tty1(),
                    'tty2' => $template->tty2(),
                ]);
            });
        });
    }
}
