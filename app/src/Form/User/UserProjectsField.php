<?php

declare(strict_types=1);

namespace App\Form\User;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The project memberships field shared by the create and edit forms: one checkbox row per project (the form theme
 * draws each row as a full-width, 44 px tap target).
 */
final class UserProjectsField
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param list<string>                $projectNames
     */
    public static function add(FormBuilderInterface $builder, array $projectNames): void
    {
        $builder->add('projects', ChoiceType::class, [
            'label' => 'Projects',
            'required' => false,
            'multiple' => true,
            'expanded' => true,
            'choices' => array_combine($projectNames, $projectNames),
            'choice_translation_domain' => false,
            'invalid_message' => 'Choose only projects that exist.',
            'help' => 'Members can operate the instances of the projects they are in. They cannot create or delete them.',
        ]);
    }
}
