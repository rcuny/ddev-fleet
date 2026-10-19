<?php

declare(strict_types=1);

namespace App\Form\Setup;

use App\Domain\User\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The first administrator. Fields are validated here for shape; the password policy and the final checks are the
 * UserService's (the controller maps its refusals onto the form). The password is never put back into the page.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class AdminAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Username',
                'disabled' => $options['username_locked'],
                'help' => $options['username_locked']
                    ? 'The administrator already exists; its username cannot change here.'
                    : 'Lowercase letters, digits, dot, underscore or dash. You sign in with it.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter a username.'),
                    new Assert\Regex(pattern: User::USERNAME_PATTERN, message: 'Use 1 to 64 lowercase letters, digits, dots, underscores or dashes, starting with a letter or digit.'),
                ],
                'attr' => ['autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter a display name.'),
                    new Assert\Length(max: 255, maxMessage: 'The display name can have at most 255 characters.'),
                ],
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new Assert\NotBlank(message: 'Enter an email address.'),
                    new Assert\Length(max: 254, maxMessage: 'The email address is too long.'),
                    new Assert\Email(message: 'Enter a valid email address.'),
                ],
                'attr' => ['autocomplete' => 'email', 'autocapitalize' => 'none'],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The two passwords do not match.',
                'first_options' => [
                    'label' => 'Password',
                    'help' => 'At least 12 characters. A passphrase works well.',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Confirm password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [new Assert\NotBlank(message: 'Enter a password.')],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save and continue']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'username_locked' => false,
        ]);
        $resolver->setAllowedTypes('username_locked', 'bool');
    }
}
