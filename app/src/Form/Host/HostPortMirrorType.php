<?php

declare(strict_types=1);

namespace App\Form\Host;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Networking tab: the switch of the in-network port mirror.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostPortMirrorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Mirror the named ports inside the Docker network',
                'required' => false,
                'help' => 'Lets a browser inside an instance open the named-port URLs (and the host on port 443). Switching it off removes the mirror files at once; the router drops the ports at the next deploy.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
