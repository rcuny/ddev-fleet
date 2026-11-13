<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Domain\Project\ProjectLink;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * FLE-299: the "new link" form of a project's Network tab. It carries no constraints: ProjectLinkService names the
 * field it refuses and ValidationErrorMapper puts the message there. The page's stateless `_token` protects it.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ProjectLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $targets */
        $targets = $options['targets'];
        $builder
            ->add('target', ChoiceType::class, [
                'label' => 'Target project',
                'choices' => $targets,
                'placeholder' => 'Choose a project',
                'required' => false,
                'help' => 'The project whose service this project may reach.',
            ])
            ->add('service', TextType::class, [
                'label' => 'Service',
                'required' => false,
                'help' => 'The compose service of the target, for example web or typesense.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'maxlength' => 63],
            ])
            ->add('port', TextType::class, [
                'label' => 'Port',
                'required' => false,
                'help' => 'The TCP port, 1 to 65535.',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'maxlength' => 5],
            ])
            ->add('label', TextType::class, [
                'label' => 'Target instance label',
                'required' => false,
                'help' => \sprintf('"%s" reaches the target instance with the same label as the source instance; a fixed label always reaches that one.', ProjectLink::SAME_LABEL),
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'maxlength' => 63],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Note',
                'required' => false,
                'help' => 'Why this link exists (optional).',
                'attr' => ['rows' => 2, 'maxlength' => 500],
            ])
            ->add('save', SubmitType::class, ['label' => 'Create link']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => false,
            'targets' => [],
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'link-form'],
        ]);
        $resolver->setAllowedTypes('targets', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'project_link';
    }
}
