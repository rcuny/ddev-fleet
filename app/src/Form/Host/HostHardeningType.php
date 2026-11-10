<?php

declare(strict_types=1);

namespace App\Form\Host;

use App\Fleet\Host\Provision\HardeningSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Hardening tab (FLE-260): the first optional role of the host. The controller checks the values with
 * HardeningSettings::violations(), so the form carries no constraints. The template disables the submit while the host
 * cannot apply or a run is active.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostHardeningType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Harden this host',
                'required' => false,
                'help' => 'SSH key-only with an allow list, fail2ban, kernel and audit settings, and a firewall (UFW) guarded by a dead-man\'s switch. Switching it off here stops managing it; it does not undo what was changed.',
            ])
            ->add('sshUsers', TextareaType::class, [
                'label' => 'SSH accounts allowed to log in',
                'required' => false,
                'help' => 'One Unix account per line. Every other account is refused by sshd.',
                'attr' => ['rows' => 3, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('invokingUser', TextType::class, [
                'label' => 'The SSH account you log in with',
                'required' => false,
                'help' => 'It must be in the list above and have an authorised key; the run refuses a change that would lock it out.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
            ])
            ->add('trustedIps', TextareaType::class, [
                'label' => 'Trusted addresses',
                'required' => false,
                'help' => 'IP addresses or CIDR ranges, one per line, that fail2ban never bans and the firewall always lets in.',
                'attr' => ['rows' => 3, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('graceMinutes', IntegerType::class, [
                'label' => 'Firewall grace period (minutes)',
                'required' => true,
                'help' => \sprintf('%d to %d. If nobody confirms the firewall from a second SSH session in this time, it is turned off again.', HardeningSettings::GRACE_MIN, HardeningSettings::GRACE_MAX),
                'attr' => ['inputmode' => 'numeric', 'min' => HardeningSettings::GRACE_MIN, 'max' => HardeningSettings::GRACE_MAX],
            ])
            ->add('hardenTmp', CheckboxType::class, [
                'label' => 'Mount /tmp noexec, nosuid, nodev',
                'required' => false,
                'help' => 'Off by default: some build tools run programs from /tmp.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Save and apply']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
