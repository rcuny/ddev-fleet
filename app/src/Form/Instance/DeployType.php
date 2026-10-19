<?php

declare(strict_types=1);

namespace App\Form\Instance;

use App\Fleet\Instances\InstanceService;
use App\Fleet\Projects\Rule\BranchName;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The deploy form. The data is a plain array (keys project, template, branch, label, count, parallel); the controller
 * builds a DeployInput and InstanceService validates it, so the server messages are the single source (`novalidate`).
 * The template choices belong to the chosen project: changing the project reloads the form.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class DeployType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $projects */
        $projects = $options['projects'];
        /** @var list<string> $templates */
        $templates = $options['templates'];
        $text = ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'];

        $builder
            ->add('project', ChoiceType::class, [
                'label' => 'Project',
                'choices' => array_combine($projects, $projects),
                'invalid_message' => 'Choose one of the projects.',
                'attr' => [
                    'data-controller' => 'reload',
                    'data-action' => 'change->reload#project',
                    'data-reload-url-value' => $options['reload_url'],
                ],
            ])
            ->add('template', ChoiceType::class, [
                'label' => 'Template',
                'required' => false,
                'placeholder' => 'Project default',
                'choices' => array_combine($templates, $templates),
                'invalid_message' => 'This project has no template with that name.',
            ])
            ->add('branch', TextType::class, [
                'label' => 'Branch',
                'required' => false,
                'help' => 'Leave blank to use the project default branch.',
                'attr' => $text + ['maxlength' => BranchName::MAX, 'placeholder' => $options['branch_placeholder']],
            ])
            ->add('label', TextType::class, [
                'label' => 'Label',
                'required' => false,
                'help' => 'Part of the instance id. Leave blank to derive it from the branch.',
                'attr' => $text + ['maxlength' => 40],
            ])
            ->add('count', IntegerType::class, [
                'label' => 'How many',
                'help' => \sprintf('1 to %d instances; more than one get numbered suffixes.', InstanceService::MAX_COUNT),
                'invalid_message' => 'Enter a whole number.',
                'attr' => ['min' => 1, 'max' => InstanceService::MAX_COUNT, 'inputmode' => 'numeric'],
            ])
            ->add('parallel', CheckboxType::class, [
                'label' => 'Deploy at the same time',
                'required' => false,
                'help' => 'By default several instances are deployed one after the other.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Deploy']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'projects' => [],
            'templates' => [],
            'branch_placeholder' => '',
            'reload_url' => '',
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'deploy-form'],
        ]);
        $resolver->setAllowedTypes('projects', 'array');
        $resolver->setAllowedTypes('templates', 'array');
        $resolver->setAllowedTypes('branch_placeholder', 'string');
        $resolver->setAllowedTypes('reload_url', 'string');
    }
}
