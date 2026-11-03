<?php

declare(strict_types=1);

namespace App\Form\Host;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Notifications tab: the SMTP relay and the sender/recipient of the host's e-mails. The password is write-only: blank
 * keeps the stored one, the checkbox removes it. Nothing here sends mail.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostNotificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('smtpHost', TextType::class, [
                'label' => 'SMTP host',
                'required' => false,
                'trim' => true,
                'help' => 'For example smtp.example.com. Leave everything empty to turn notification e-mail off.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'maxlength' => 253],
            ])
            ->add('smtpPort', IntegerType::class, [
                'label' => 'SMTP port',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'min' => 1, 'max' => 65535],
            ])
            ->add('smtpUser', TextType::class, [
                'label' => 'SMTP user',
                'required' => false,
                'trim' => true,
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'maxlength' => 254],
            ])
            ->add('smtpPassword', PasswordType::class, [
                'label' => $options['passwordStored'] ? 'New SMTP password' : 'SMTP password',
                'required' => false,
                'always_empty' => true,
                'trim' => false,
                'help' => $options['passwordStored']
                    ? 'A password is stored. Leave blank to keep it, or type a new one to replace it.'
                    : 'Stored encrypted; never shown again.',
                'attr' => ['autocomplete' => 'new-password', 'maxlength' => 256],
            ])
            ->add('clearPassword', CheckboxType::class, [
                'label' => 'Remove the stored password',
                'required' => false,
            ])
            ->add('mailFrom', EmailType::class, [
                'label' => 'Sender address',
                'required' => false,
                'trim' => true,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 254],
            ])
            ->add('mailTo', EmailType::class, [
                'label' => 'Recipient address',
                'required' => false,
                'trim' => true,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 254],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'passwordStored' => false]);
        $resolver->setAllowedTypes('passwordStored', 'bool');
    }
}
