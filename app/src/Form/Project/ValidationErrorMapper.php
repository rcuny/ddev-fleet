<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Fleet\Projects\ValidationFailed;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

/**
 * Puts the field-keyed errors of a service on a Symfony form: the message goes under the field of the same name, and a
 * key the form has no field for (a service-level key such as `ports`, or `defaultTemplate` on the create form) becomes
 * a form-level error so no message is lost.
 */
final class ValidationErrorMapper
{
    /**
     * @param FormInterface<mixed> $form
     */
    public static function add(FormInterface $form, ValidationFailed $failed): void
    {
        foreach ($failed->errors as $field => $message) {
            if ($form->has((string) $field)) {
                $form->get((string) $field)->addError(new FormError($message));
            } else {
                $form->addError(new FormError($message));
            }
        }
    }
}
