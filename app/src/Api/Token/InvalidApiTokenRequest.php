<?php

declare(strict_types=1);

namespace App\Api\Token;

/** A token that cannot be issued as asked (the message says why and is safe to show to the admin). */
final class InvalidApiTokenRequest extends \InvalidArgumentException
{
}
