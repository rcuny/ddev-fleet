<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Domain\Project\GitBotMode;
use App\Domain\Project\Project;
use App\Fleet\Projects\Rule\BranchName;
use App\Fleet\Projects\Rule\GitIdentity;
use App\Fleet\Projects\Rule\GitUrl;
use App\Fleet\Projects\Rule\IssueIdPattern;
use App\Fleet\Projects\Rule\ResourceName;
use App\Fleet\Projects\Rule\SubmodulePath;
use App\Fleet\Projects\Rule\TextBlock;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The General tab of a project. The data is a plain array (keys = ProjectInput properties, hostnames one per line); the
 * controller turns it into a ProjectInput and the service validates it: the form carries no constraints, so the server
 * messages are the single source (the form is `novalidate`, there is no HTML5 pattern).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ProjectType extends AbstractType
{
    public const string CREATE = 'create';
    public const string EDIT = 'edit';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $create = self::CREATE === $options['mode'];
        $text = ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'];

        if ($create) {
            $builder->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'Lowercase letters, digits and single dashes. It becomes part of every instance id and cannot be changed later.',
                'attr' => $text + ['maxlength' => ResourceName::MAX],
            ]);
        }

        $builder->add('git', TextType::class, [
            'label' => 'Git URL',
            'help' => 'An https:// or ssh:// URL, or user@host:path. Use a deploy key, not a token in the URL.',
            'attr' => $text + ['inputmode' => 'url', 'maxlength' => GitUrl::MAX],
        ]);
        $builder->add('defaultBranch', TextType::class, [
            'label' => 'Default branch',
            'required' => false,
            'help' => 'The branch a new instance starts from. Leave blank to use the repository default.',
            'attr' => $text + ['maxlength' => BranchName::MAX],
        ]);

        if (!$create) {
            /** @var list<string> $templates */
            $templates = $options['templates'];
            $builder->add('defaultTemplate', ChoiceType::class, [
                'label' => 'Default template',
                'required' => false,
                'placeholder' => 'None',
                'choices' => array_combine($templates, $templates),
                'invalid_message' => 'This project has no template with this name.',
                'help' => [] === $templates ? 'Add a template first, then choose which one is the default.' : 'The template a new instance uses when none is chosen.',
            ]);
        }

        $builder
            ->add('additionalHostnames', TextareaType::class, [
                'label' => 'Additional hostnames',
                'required' => false,
                'help' => 'One label per line. Each instance also answers on <label>-<project>--<instance>.',
                'attr' => $text + ['rows' => 3, 'maxlength' => TextBlock::MAX],
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
            ->add('issueIdRegexp', TextType::class, [
                'label' => 'Issue id pattern',
                'required' => false,
                'help' => 'A regular expression that finds the issue id in a branch name, for example (?:ABC|XYZ)-\d{1,6}.',
                'attr' => $text + ['maxlength' => IssueIdPattern::MAX],
            ])
            ->add('displaySubmoduleBranch', TextType::class, [
                'label' => 'Submodule whose branch is shown',
                'required' => false,
                'help' => 'A path inside the repository, for example web/modules/custom/blog.',
                'attr' => $text + ['maxlength' => SubmodulePath::MAX],
            ])
            ->add('typesense', CheckboxType::class, [
                'label' => 'Run a Typesense search server for each instance',
                'required' => false,
            ])
            ->add('save', SubmitType::class, ['label' => $create ? 'Create project' : 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'mode' => self::EDIT,
            'templates' => [],
            // Every message comes from the service; a stray HTML5 attribute would hide it.
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'project-form'],
        ]);
        $resolver->setAllowedValues('mode', [self::CREATE, self::EDIT]);
        $resolver->setAllowedTypes('templates', 'array');
    }
}
