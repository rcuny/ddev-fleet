<?php

declare(strict_types=1);

namespace App\Form\Host;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Claude token tab: set or replace the fleet's Claude Code OAuth token. Write-only, like every secret field.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostClaudeTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('token', PasswordType::class, [
                'label' => $options['stored'] ? 'New Claude OAuth token' : 'Claude OAuth token',
                'required' => false,
                'always_empty' => true,
                'help' => 'Run "claude setup-token" on any machine and paste the result. The stored value is never shown again.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'maxlength' => 512],
            ])
            ->add('save', SubmitType::class, ['label' => $options['stored'] ? 'Replace token' : 'Store token']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'stored' => false]);
        $resolver->setAllowedTypes('stored', 'bool');
    }
}
