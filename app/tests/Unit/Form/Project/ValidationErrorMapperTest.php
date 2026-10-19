<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form\Project;

use App\Fleet\Projects\ValidationFailed;
use App\Form\Project\ValidationErrorMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

final class ValidationErrorMapperTest extends TestCase
{
    public function testFieldKeysGoToTheFieldAndOtherKeysToTheForm(): void
    {
        $form = Forms::createFormFactory()->createBuilder(FormType::class)
            ->add('git', TextType::class)
            ->add('defaultBranch', TextType::class)
            ->getForm();

        ValidationErrorMapper::add($form, new ValidationFailed([
            'git' => 'Bad git.',
            // Keys the form has no field for: service-level keys and the CLI-only ones.
            'ports' => 'Unknown port(s): x.',
            'name' => 'The name cannot be changed.',
            'defaultTemplate' => 'No such template.',
        ]));

        self::assertSame(['Bad git.'], $this->messages($form->get('git')));
        self::assertSame([], $this->messages($form->get('defaultBranch')));
        self::assertSame(
            ['Unknown port(s): x.', 'The name cannot be changed.', 'No such template.'],
            $this->messages($form),
        );
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return list<string>
     */
    private function messages(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors() as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
