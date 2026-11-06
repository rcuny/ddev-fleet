<?php

declare(strict_types=1);

namespace App\Form\Host;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Capacity tab (FLE-203): the threads kept for the system and the cap on existing instances. The controller checks the
 * ranges (they depend on the detected threads), so the form carries no constraints.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostCapacityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reserve', IntegerType::class, [
                'label' => 'Reserved threads',
                'required' => true,
                'help' => 'Kept for the system, Docker, Caddy, php-fpm and the Fleet workers. Allocatable threads = threads minus this.',
                'attr' => ['inputmode' => 'numeric', 'min' => 0],
            ])
            ->add('maxInstances', IntegerType::class, [
                'label' => 'Maximum instances',
                'required' => true,
                'help' => 'Existing instances on this host, whatever their state. The dispatch gate refuses a deploy above it.',
                'attr' => ['inputmode' => 'numeric', 'min' => 1],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
