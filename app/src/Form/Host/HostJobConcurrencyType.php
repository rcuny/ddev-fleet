<?php

declare(strict_types=1);

namespace App\Form\Host;

use App\Domain\Host\HostSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Jobs tab: how many jobs of different instances the worker runs at the same time.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostJobConcurrencyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('jobs', IntegerType::class, [
                'label' => 'Jobs at the same time',
                'required' => true,
                'help' => \sprintf('1 to %d. 1 runs one job after another. Jobs of one instance never overlap, whatever this is.', HostSettings::MAX_MAX_CONCURRENT_JOBS),
                'attr' => ['inputmode' => 'numeric', 'min' => 1, 'max' => HostSettings::MAX_MAX_CONCURRENT_JOBS],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
