<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

/**
 * The router could not be brought up with the mirror. By the time this is thrown the files are removed, the failed
 * marker is written and the router was restarted without the mirror.
 */
final class PortMirrorFailed extends \RuntimeException
{
}
