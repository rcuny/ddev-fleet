<?php

declare(strict_types=1);

namespace App\Fleet\Projects;

/**
 * The operation is valid but not allowed in the current state; the message states the reason, for example
 * "2 instance(s) exist: destroy them first.".
 */
final class Refused extends \DomainException
{
}
