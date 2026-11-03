<?php

declare(strict_types=1);

namespace App\Form\Host;

use App\Fleet\Host\GitIdentityRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Git identity tab: the default author of the commits the fleet makes in instances.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HostGitIdentityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'required' => false,
                'trim' => true,
                'attr' => ['autocomplete' => 'off', 'maxlength' => GitIdentityRule::MAX_NAME_LENGTH],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'trim' => true,
                'help' => 'Set both or neither. Projects can override this identity.',
                'attr' => ['autocomplete' => 'off', 'maxlength' => GitIdentityRule::MAX_EMAIL_LENGTH],
            ])
            ->add('save', SubmitType::class, ['label' => 'Save']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
