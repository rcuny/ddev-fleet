<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Fleet\Auth\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Profile, role and project memberships of an existing user (data: displayName, email, admin, projects). The username
 * is fixed. When an administrator edits their own account the role is shown but cannot be changed (`self`).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 255],
            ])
            ->add('siteOnly', CheckboxType::class, [
                'label' => 'Site access only',
                'required' => false,
                'help' => 'Only opens the instance sites of the projects below, for people you share a site with: no Fleet access, no email. The password can be as short as '.PasswordPolicy::SITE_ONLY_MIN_LENGTH.' characters.',
                'attr' => ['data-site-only-target' => 'box', 'data-action' => 'site-only#toggle'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'inputmode' => 'email', 'maxlength' => 254],
            ])
            ->add('admin', CheckboxType::class, [
                'label' => 'Administrator',
                'required' => false,
                'disabled' => $options['self'],
                'help' => $options['self']
                    ? 'You cannot change your own role. Ask another administrator.'
                    : 'Administrators manage everything and can operate every project.',
            ]);

        UserProjectsField::add($builder, $options['projects']);

        $builder->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'projects' => [],
            'self' => false,
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'user-form', 'data-controller' => 'site-only'],
        ]);
        $resolver->setAllowedTypes('projects', 'array');
        $resolver->setAllowedTypes('self', 'bool');
    }
}
