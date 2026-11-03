<?php

declare(strict_types=1);

namespace App\Form\Setup;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The optional Claude Code OAuth token (`claude setup-token`). Write-only like every secret field.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ClaudeTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('token', PasswordType::class, [
                'label' => 'Claude OAuth token',
                'required' => false,
                'help' => $options['stored']
                    ? 'A token is stored. Leave blank to keep it, or paste a new one to replace it.'
                    : 'Run "claude setup-token" on any machine and paste the result. Optional: you can add it later.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save and continue'])
            ->add('skip', SubmitType::class, ['label' => 'Skip for now', 'attr' => ['data-variant' => 'outline']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'stored' => false]);
        $resolver->setAllowedTypes('stored', 'bool');
    }
}
