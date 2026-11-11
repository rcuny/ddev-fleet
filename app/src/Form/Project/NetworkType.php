<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Fleet\Projects\Rule\TextBlock;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Network tab of a project: additional hostnames and the Typesense toggle. The port table is plain markup in the
 * same <form> (one row per catalogue port); the controller reads it from the request. Like ProjectType, the form
 * carries no constraints (the services' messages are the single source) and uses the page's stateless `_token`.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class NetworkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('additionalHostnames', TextareaType::class, [
                'label' => 'Additional hostnames',
                'required' => false,
                'help' => 'One label per line. Each instance also answers on <label>-<project>--<instance>. Adding a name asks for a confirmation.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'rows' => 3, 'maxlength' => TextBlock::MAX],
            ])
            ->add('typesense', CheckboxType::class, [
                'label' => 'Run a Typesense search server for each instance',
                'required' => false,
            ])
            ->add('typesenseDashboard', CheckboxType::class, [
                'label' => 'Also run the Typesense dashboard',
                'required' => false,
                'help' => 'The dashboard has no authentication of its own and is off by default. It is reachable only behind the Authelia gate; switch it on only when you need it.',
            ])
            ->add('save', SubmitType::class, ['label' => 'Save network settings']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => false,
            'attr' => ['novalidate' => 'novalidate', 'data-testid' => 'ports-form'],
        ]);
    }
}
