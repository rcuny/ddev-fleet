<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Fleet\Projects\Rule\ResourceName;
use App\Fleet\Projects\Rule\TextBlock;
use Symfony\Component\Form\AbstractType;
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
        $commands = $text + ['wrap' => 'off', 'class' => 'font-mono', 'maxlength' => TextBlock::MAX];

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
                'help' => 'One command per line, run on the host after deploy through ddev exec; a failing command aborts the deploy.',
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
            ->add('save', SubmitType::class, ['label' => $create ? 'Create template' : 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'mode' => self::EDIT,
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'template-form'],
        ]);
        $resolver->setAllowedValues('mode', [self::CREATE, self::EDIT]);
    }
}
