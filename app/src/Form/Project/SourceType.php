<?php

declare(strict_types=1);

namespace App\Form\Project;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Source tab of a project: where the code comes from (Git URL, default branch, git identity, submodule shown, issue
 * id pattern). The fields that have not moved to their own tab yet (hostnames, Typesense, container SSH, capacity) stay
 * in this form so it still saves everything; ProjectInput::with() keeps whatever the form does not carry (ports, default
 * template). Same data shape and field names as ProjectType (the block prefix stays `project`).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class SourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        ProjectFields::source($builder);
        ProjectFields::other($builder, $options['allocatable']);
        $builder->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function getBlockPrefix(): string
    {
        return 'project';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'allocatable' => null,
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'project-form'],
        ]);
        $resolver->setAllowedTypes('allocatable', ['null', 'int']);
    }
}
