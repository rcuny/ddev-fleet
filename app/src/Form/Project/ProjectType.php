<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Fleet\Projects\Rule\ResourceName;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "New project" form: the name plus every field of the project. Editing is split by tab (SourceType for now). The data
 * is a plain array (keys = ProjectInput properties, hostnames one per line); the controller turns it into a ProjectInput
 * and the service validates it: the form carries no constraints, so the server messages are the single source (the form
 * is `novalidate`, there is no HTML5 pattern).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Name',
            'help' => 'Lowercase letters, digits and single dashes. It becomes part of every instance id and cannot be changed later.',
            'attr' => ProjectFields::textAttr() + ['maxlength' => ResourceName::MAX],
        ]);
        ProjectFields::source($builder);
        ProjectFields::other($builder);
        CapacityType::addCapFields($builder, $options['allocatable']);
        $builder->add('save', SubmitType::class, ['label' => 'Create project']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'allocatable' => null,
            // Every message comes from the service; a stray HTML5 attribute would hide it.
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'project-form'],
        ]);
        $resolver->setAllowedTypes('allocatable', ['null', 'int']);
    }
}
