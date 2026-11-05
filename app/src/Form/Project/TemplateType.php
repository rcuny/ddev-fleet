<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Fleet\Projects\Rule\CommandLines;
use App\Fleet\Projects\Rule\ResourceName;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A template of a project. The data is a plain array (keys = TemplateInput properties; the three command lists are
 * textareas, one command per line); the controller turns it into a TemplateInput and the service validates it. The form
 * carries no constraints and is `novalidate`, so the server messages are the single source. Command lines are text: they
 * are shown in a monospace textarea and never interpreted here.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class TemplateType extends AbstractType
{
    public const string CREATE = 'create';
    public const string EDIT = 'edit';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $create = self::CREATE === $options['mode'];
        $text = ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'];
        $commands = $text + ['wrap' => 'off', 'class' => 'font-mono', 'maxlength' => CommandLines::MAX_TEXT];

        if ($create) {
            $builder->add('name', TextType::class, [
                'label' => 'Name',
                'help' => 'Lowercase letters, digits and single dashes. It cannot be changed later.',
                'attr' => $text + ['maxlength' => ResourceName::MAX],
            ]);
        }

        $builder
            ->add('drupalEnv', TextType::class, [
                'label' => 'Drupal environment',
                'required' => false,
                'help' => "Written as DRUPAL_ENV=<value> into the instance's .env. Leave empty to keep the project's own.",
                'attr' => $text + ['maxlength' => 64],
            ])
            ->add('postDeploy', TextareaType::class, [
                'label' => 'Post-deploy commands',
                'required' => false,
                'help' => "One command per line, run inside the instance's web container with ddev exec after a deploy (for example composer install or drush deploy).",
                'attr' => $commands + ['rows' => 8],
            ])
            ->add('tty1', TextareaType::class, [
                'label' => 'Terminal 1 commands',
                'required' => false,
                'help' => 'Typed into the middle terminal pane when the instance window is first created.',
                'attr' => $commands + ['rows' => 3],
            ])
            ->add('tty2', TextareaType::class, [
                'label' => 'Terminal 2 commands',
                'required' => false,
                'help' => 'Typed into the right terminal pane when the instance window is first created.',
                'attr' => $commands + ['rows' => 3],
            ])
            ->add('sshKeys', TextareaType::class, [
                'label' => 'Extra SSH keys',
                'required' => false,
                'help' => "One key name per line: the file ssh/<name>_ed25519 in the fleet home, loaded into the instance's own ssh-agent next to the push key. The project needs git over SSH in the container.",
                'attr' => $text + ['wrap' => 'off', 'class' => 'font-mono', 'rows' => 2, 'maxlength' => 400],
            ])
            ->add('cpuThreads', IntegerType::class, [
                'label' => 'CPU threads',
                'required' => false,
                'help' => null === $options['projectCpuThreads']
                    ? 'The most CPU threads all instances of this template may use together. Leave empty to inherit the project, which has no limit.'
                    : \sprintf('The most CPU threads all instances of this template may use together, at most %1$d (the project). Leave empty to inherit the project: %1$d.', $options['projectCpuThreads']),
                'attr' => ['inputmode' => 'numeric', 'min' => 1, 'placeholder' => null === $options['projectCpuThreads'] ? 'inherits project: unlimited' : \sprintf('inherits project: %d', $options['projectCpuThreads'])],
            ])
            ->add('slots', IntegerType::class, [
                'label' => 'Slots',
                'required' => false,
                'help' => null === $options['projectSlots']
                    ? 'The most runs of this template at the same time. Leave empty for no limit.'
                    : \sprintf('The most runs of this template at the same time, at most %d (the project). Leave empty for no limit of its own.', $options['projectSlots']),
                'attr' => ['inputmode' => 'numeric', 'min' => 1, 'placeholder' => 'unlimited'],
            ])
            ->add('save', SubmitType::class, ['label' => $create ? 'Create template' : 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'mode' => self::EDIT,
            'projectCpuThreads' => null,
            'projectSlots' => null,
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'template-form'],
        ]);
        $resolver->setAllowedValues('mode', [self::CREATE, self::EDIT]);
        $resolver->setAllowedTypes('projectCpuThreads', ['null', 'int']);
        $resolver->setAllowedTypes('projectSlots', ['null', 'int']);
    }
}
