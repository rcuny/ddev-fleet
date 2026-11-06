<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Domain\Project\GitBotMode;
use App\Fleet\Projects\Rule\BranchName;
use App\Fleet\Projects\Rule\GitIdentity;
use App\Fleet\Projects\Rule\GitUrl;
use App\Fleet\Projects\Rule\IssueIdPattern;
use App\Fleet\Projects\Rule\SubmodulePath;
use App\Fleet\Projects\Rule\TextBlock;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The field definitions the project forms share: ProjectType (create, every field) and SourceType (the Source tab).
 * Fields are grouped by the tab they belong to; the group still in `other()` moves out in later tickets.
 */
final class ProjectFields
{
    private const array TEXT = ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'];

    /**
     * Git URL, default branch, git identity, submodule whose branch is shown and issue id pattern: the Source tab.
     *
     * @param FormBuilderInterface<mixed> $builder
     */
    public static function source(FormBuilderInterface $builder): void
    {
        $text = self::TEXT;

        $builder
            ->add('git', TextType::class, [
                'label' => 'Git URL',
                'help' => 'An https:// or ssh:// URL, or user@host:path. Use a deploy key, not a token in the URL.',
                'attr' => $text + ['inputmode' => 'url', 'maxlength' => GitUrl::MAX],
            ])
            ->add('defaultBranch', TextType::class, [
                'label' => 'Default branch',
                'required' => false,
                'help' => 'The branch a new instance starts from. Leave blank to use the repository default.',
                'attr' => $text + ['maxlength' => BranchName::MAX],
            ])
            ->add('gitBotMode', EnumType::class, [
                'class' => GitBotMode::class,
                'label' => 'Git identity of the instances',
                'expanded' => true,
                'choice_label' => static fn (GitBotMode $mode): string => match ($mode) {
                    GitBotMode::Inherit => 'Use the host default',
                    GitBotMode::Disabled => 'Do not set a git identity',
                    GitBotMode::Override => 'Custom identity',
                },
                'invalid_message' => 'Choose one of the options.',
            ])
            ->add('gitBotName', TextType::class, [
                'label' => 'Identity name',
                'required' => false,
                'help' => 'Used with "Custom identity". A blank field falls back to the host default.',
                'attr' => $text + ['maxlength' => GitIdentity::NAME_MAX],
            ])
            ->add('gitBotEmail', TextType::class, [
                'label' => 'Identity email',
                'required' => false,
                'attr' => $text + ['inputmode' => 'email', 'maxlength' => 254],
            ])
            ->add('displaySubmoduleBranch', TextType::class, [
                'label' => 'Submodule whose branch is shown',
                'required' => false,
                'help' => 'A path inside the repository, for example web/modules/custom/blog.',
                'attr' => $text + ['maxlength' => SubmodulePath::MAX],
            ])
            ->add('issueIdRegexp', TextType::class, [
                'label' => 'Issue id pattern',
                'required' => false,
                'help' => 'A regular expression that finds the issue id in a branch name, for example (?:ABC|XYZ)-\d{1,6}.',
                'attr' => $text + ['maxlength' => IssueIdPattern::MAX],
            ]);
    }

    /**
     * Fields that have not moved to their own tab yet (Network and Keys tickets): they stay in the form so it still
     * saves everything.
     *
     * @param FormBuilderInterface<mixed> $builder
     */
    public static function other(FormBuilderInterface $builder): void
    {
        $builder
            ->add('additionalHostnames', TextareaType::class, [
                'label' => 'Additional hostnames',
                'required' => false,
                'help' => 'One label per line. Each instance also answers on <label>-<project>--<instance>.',
                'attr' => self::TEXT + ['rows' => 3, 'maxlength' => TextBlock::MAX],
            ])
            ->add('typesense', CheckboxType::class, [
                'label' => 'Run a Typesense search server for each instance',
                'required' => false,
            ])
            ->add('containerSsh', CheckboxType::class, [
                'label' => 'Git over SSH inside the container: a dedicated ssh-agent with the push key for each instance',
                'required' => false,
                'help' => 'Only for projects whose agents push from the web container. The key is usable by every process in that instance\'s container while the agent runs.',
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function textAttr(): array
    {
        return self::TEXT;
    }
}
