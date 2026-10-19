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
 * Uploads tab: the largest file the browser may upload into a project's assets, in MiB.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostUploadLimitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('megabytes', IntegerType::class, [
                'label' => 'Largest file (MiB)',
                'required' => true,
                'help' => \sprintf('1 to %d. Bigger files are copied into the assets directory over SSH.', HostSettings::MAX_ASSET_UPLOAD_MEBIBYTES),
                'attr' => ['inputmode' => 'numeric', 'min' => 1, 'max' => HostSettings::MAX_ASSET_UPLOAD_MEBIBYTES],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
