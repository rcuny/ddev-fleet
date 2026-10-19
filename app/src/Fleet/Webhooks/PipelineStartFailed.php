<?php

declare(strict_types=1);

namespace App\Fleet\Webhooks;

/**
 * Bitbucket refused or could not be reached. The message is fixed text plus an HTTP status or an exception class: it
 * never echoes the request, the response or the token.
 */
final class PipelineStartFailed extends \RuntimeException
{
}
