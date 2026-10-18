<?php

declare(strict_types=1);

namespace App\Form\Setup;

use App\Domain\Host\TlsMode;
use App\Fleet\System\ConfigValue;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * How Caddy gets certificates. The OVH credentials are write-only: a stored value is never shown, and a blank field
 * keeps it (the `stored` option only switches the hint text).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class TlsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $stored */
        $stored = $options['stored'];
        $hint = static fn (string $name, string $what): string => \in_array($name, $stored, true)
            ? 'Stored. Leave blank to keep it.'
            : $what;

        $builder
            ->add('mode', ChoiceType::class, [
                'label' => 'Certificates',
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Automatic, per host name (HTTP-01). Needs DNS for the domain and auth.<domain> pointing here.' => TlsMode::Http01->value,
                    'DNS challenge through the OVH API (DNS-01). Needs OVH API credentials.' => TlsMode::OvhDns->value,
                ],
                'choice_translation_domain' => false,
                'invalid_message' => 'Choose one of the two modes.',
            ])
            ->add('ovhEndpoint', ChoiceType::class, [
                'label' => 'OVH API endpoint',
                'choices' => array_combine(ConfigValue::OVH_ENDPOINTS, ConfigValue::OVH_ENDPOINTS),
                'invalid_message' => 'Choose one of the listed OVH endpoints.',
                'help' => 'Use an API application created on the regional OVH site of that endpoint.',
            ])
            ->add('ovhApplicationKey', PasswordType::class, [
                'label' => 'OVH application key',
                'required' => false,
                'help' => $hint('ovh_application_key', 'From your OVH API application.'),
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ])
            ->add('ovhApplicationSecret', PasswordType::class, [
                'label' => 'OVH application secret',
                'required' => false,
                'help' => $hint('ovh_application_secret', 'From your OVH API application.'),
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ])
            ->add('ovhConsumerKey', PasswordType::class, [
                'label' => 'OVH consumer key',
                'required' => false,
                'help' => $hint('ovh_consumer_key', 'Needs the right to manage DNS records of the zone.'),
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save and continue']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'stored' => []]);
        $resolver->setAllowedTypes('stored', 'string[]');
    }
}
