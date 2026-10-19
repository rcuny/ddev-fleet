<?php

declare(strict_types=1);

namespace App\Form\Host;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * General tab: the one editable value, the ACME contact address. (Validated with ConfigValue by the controller:
 * it is rendered into the Caddyfile.)
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostGeneralType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('acmeEmail', EmailType::class, [
                'label' => 'ACME contact e-mail',
                'required' => false,
                'trim' => true,
                'help' => 'The address the certificate authority may use to warn about expiring certificates. Optional.',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 254],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
