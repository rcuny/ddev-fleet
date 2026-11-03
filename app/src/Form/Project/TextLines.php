<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Fleet\Projects\Rule\CommandLines;
use App\Fleet\Projects\Rule\TextBlock;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

/**
 * A textarea as a list of lines. The raw text is bounded first (a megabyte must not be split, NUL and invalid UTF-8 never
 * get through); a refused text becomes an error on that field and the method returns null. What the lines may contain is
 * decided by the service afterwards.
 */
final class TextLines
{
    /**
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $data     the submitted form data
     * @param bool                 $commands a command list: bounded by CommandLines::MAX_TEXT instead of TextBlock::MAX
     *
     * @return list<string>|null
     */
    public static function split(FormInterface $form, string $field, array $data, bool $commands = false): ?array
    {
        $text = $data[$field] ?? '';
        $text = \is_string($text) ? $text : '';
        if (null !== $error = $commands ? CommandLines::textError($text) : TextBlock::error($text)) {
            $form->get($field)->addError(new FormError($error));

            return null;
        }

        return CommandLines::normalise($text);
    }
}
