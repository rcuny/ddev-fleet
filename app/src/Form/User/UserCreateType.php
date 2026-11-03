<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Fleet\Auth\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "New user" form. The data is a plain array; the controller hands it to UserService, whose messages (an
 * InvalidUserInput names its field) are the single source of validation: the form carries no constraints and is
 * `novalidate`, like the project forms.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class UserCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'];

        $builder
            ->add('username', TextType::class, [
                'label' => 'Username',
                'help' => 'Lowercase letters, digits, dot, underscore or dash. It is what the person types to sign in and cannot be changed later.',
                'attr' => $text + ['maxlength' => 64],
            ])
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
                'attr' => $text + ['inputmode' => 'email', 'maxlength' => 254],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Initial password',
                'help' => \sprintf('At least %d characters. Tell the person to change it after the first sign-in.', PasswordPolicy::MIN_LENGTH),
                'attr' => ['autocomplete' => 'new-password', 'maxlength' => PasswordPolicy::MAX_LENGTH],
            ])
            ->add('admin', CheckboxType::class, [
                'label' => 'Administrator',
                'required' => false,
                'help' => 'Administrators manage everything and can operate every project.',
            ]);

        UserProjectsField::add($builder, $options['projects']);

        $builder->add('save', SubmitType::class, ['label' => 'Create user']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'projects' => [],
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'user-form', 'data-controller' => 'site-only'],
        ]);
        $resolver->setAllowedTypes('projects', 'array');
    }
}
