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
 * Backups tab: how many days backups are kept.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostBackupRetentionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('days', IntegerType::class, [
                'label' => 'Keep backups for (days)',
                'required' => true,
                'help' => \sprintf('1 to %d. The newest backup is always kept, whatever its age.', HostSettings::MAX_BACKUP_RETENTION_DAYS),
                'attr' => ['inputmode' => 'numeric', 'min' => 1, 'max' => HostSettings::MAX_BACKUP_RETENTION_DAYS],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
