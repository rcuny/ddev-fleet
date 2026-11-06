<?php

declare(strict_types=1);

namespace App\Form\Project;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Capacity tab of a project: its CPU threads and slots. Like ProjectType, the form carries no constraints; the
 * service validates and its messages are mapped onto the fields.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class CapacityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        self::addCapFields($builder, $options['allocatable']);
        $builder->add('save', SubmitType::class, ['label' => 'Save']);
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    public static function addCapFields(FormBuilderInterface $builder, ?int $allocatable): void
    {
        $builder
            ->add('cpuThreads', IntegerType::class, [
                'label' => 'CPU threads',
                'required' => false,
                'help' => null === $allocatable
                    ? 'The most CPU threads all instances of this project may use together. Leave empty for no limit.'
                    : \sprintf('The most CPU threads all instances of this project may use together. Leave empty for no limit. This host can allocate %d.', $allocatable),
                'attr' => ['inputmode' => 'numeric', 'min' => 1, 'placeholder' => 'unlimited'],
            ])
            ->add('slots', IntegerType::class, [
                'label' => 'Slots',
                'required' => false,
                'help' => 'The most runs (CI suites, working agents) of this project at the same time, across its templates. Leave empty for no limit.',
                'attr' => ['inputmode' => 'numeric', 'min' => 1, 'placeholder' => 'unlimited'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'allocatable' => null,
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'project-form'],
        ]);
        $resolver->setAllowedTypes('allocatable', ['null', 'int']);
    }
}
