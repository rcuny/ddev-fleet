<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * The setup flow cannot do what was asked right now (not initialised, already complete, a precondition is missing,
 * another Finish is running). The message is safe to show to the person doing the setup.
 */
final class SetupRefused extends \RuntimeException
{
}
