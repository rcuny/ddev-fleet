<?php

declare(strict_types=1);

namespace App\Form\Host;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Isolation tab: the extra host ports every instance may reach, comma separated (FLE-299). Validated by the controller.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostIsolationPortsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ports', TextType::class, [
                'label' => 'Extra host ports',
                'required' => false,
                'help' => 'Comma separated TCP ports from 1 to 65535, for example 9100, 9200. Added to the built-in list below.',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'spellcheck' => 'false', 'maxlength' => 200],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save ports']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
