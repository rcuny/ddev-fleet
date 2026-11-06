<?php

declare(strict_types=1);

namespace App\Form\Project;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The default template choice, on the Templates tab (edit only: a new project has no template yet).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class DefaultTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $templates */
        $templates = $options['templates'];
        $builder
            ->add('defaultTemplate', ChoiceType::class, [
                'label' => 'Default template',
                'required' => false,
                'placeholder' => 'None',
                'choices' => array_combine($templates, $templates),
                'invalid_message' => 'This project has no template with this name.',
                'help' => 'The template a new instance uses when none is chosen.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Save default']);
    }

    public function getBlockPrefix(): string
    {
        return 'project';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'templates' => [],
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'default-template-form'],
        ]);
        $resolver->setAllowedTypes('templates', 'array');
    }
}
