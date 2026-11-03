<?php

declare(strict_types=1);

namespace App\Command\Webhook;

use App\Command\Support\FieldErrors;
use App\Command\Support\JsonOutput;
use App\Domain\Webhook\WebhookRule;
use App\Domain\Webhook\WebhookSource;
use App\Fleet\Webhooks\WebhookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fleet:webhook:rule:add', description: 'Add a rule to a project webhook (the webhook needs a secret first)')]
final class WebhookRuleAddCommand extends Command
{
    /** option name => [where it goes, key under its 0.x name] */
    private const array MATCH = ['on-status' => 'on_status', 'on-event' => 'on_event', 'repo' => 'repo', 'match-branch' => 'branch', 'state' => 'state', 'comment' => 'comment'];
    private const array PARAMS = ['template' => 'template', 'branch' => 'branch', 'label' => 'label', 'label-prefix' => 'label_prefix', 'strip-suffix' => 'strip_suffix', 'pattern' => 'pattern', 'ref' => 'ref'];

    public function __construct(private readonly WebhookService $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project', InputArgument::REQUIRED, 'Project name');
        WebhookSourceOption::add($this);
        $this->addOption('action', null, InputOption::VALUE_REQUIRED, 'deploy, or run-pipeline (Bitbucket)', WebhookRule::ACTION_DEPLOY);
        $this->addOption('on-status', null, InputOption::VALUE_REQUIRED, 'Jira: the status an issue moves into, e.g. Dispatched');
        $this->addOption('on-event', null, InputOption::VALUE_REQUIRED, 'Bitbucket: the event key, e.g. pullrequest:approved');
        $this->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Bitbucket: workspace/slug the event must come from');
        $this->addOption('match-branch', null, InputOption::VALUE_REQUIRED, 'Bitbucket: branch glob the event must concern, e.g. renovate/*');
        $this->addOption('state', null, InputOption::VALUE_REQUIRED, 'Bitbucket: commit status state, e.g. SUCCESSFUL');
        $this->addOption('comment', null, InputOption::VALUE_REQUIRED, 'Bitbucket: a comment line to look for, e.g. /merge');
        $this->addOption('template', null, InputOption::VALUE_REQUIRED, 'deploy: the project template to deploy');
        $this->addOption('branch', null, InputOption::VALUE_REQUIRED, 'deploy (Jira): the branch to deploy, default the project\'s');
        $this->addOption('label', null, InputOption::VALUE_REQUIRED, 'deploy (Bitbucket): a fixed instance label, default derived from the branch');
        $this->addOption('label-prefix', null, InputOption::VALUE_REQUIRED, 'deploy (Bitbucket): put this in front of the branch to form the label; never overwrites an instance');
        $this->addOption('strip-suffix', null, InputOption::VALUE_REQUIRED, 'deploy (Bitbucket): text removed from the end of the branch first; needs --label-prefix, and the branch glob must end with it');
        $this->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'run-pipeline: the custom pipeline name');
        $this->addOption('ref', null, InputOption::VALUE_REQUIRED, 'run-pipeline: the branch to run it on');
        JsonOutput::addOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $out = JsonOutput::of($input, $output, FieldErrors::webhook());

        return $out->run(function () use ($input, $out): void {
            $source = WebhookSourceOption::read($input);
            $rule = $this->webhooks->addRule(
                (string) $input->getArgument('project'),
                $source,
                (string) $input->getOption('action'),
                self::collect($input, self::MATCH),
                self::collect($input, self::PARAMS),
            );
            $out->emit(['id' => $rule->id(), 'source' => $source->value, 'action' => $rule->action(), 'match' => $rule->match(), 'params' => $rule->params()], static function (JsonOutput $out) use ($rule, $source): void {
                $out->success(\sprintf('Rule %d added to %s: %s -> %s.', $rule->id(), WebhookSource::Jira === $source ? 'Jira' : 'Bitbucket', $rule->summary(), $rule->action()));
            });
        });
    }

    /**
     * @param array<string, string> $map option name => key
     *
     * @return array<string, string>
     */
    private static function collect(InputInterface $input, array $map): array
    {
        $values = [];
        foreach ($map as $option => $key) {
            $value = $input->getOption($option);
            if (\is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
