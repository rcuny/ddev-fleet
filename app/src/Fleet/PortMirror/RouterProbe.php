<?php

declare(strict_types=1);

namespace App\Fleet\PortMirror;

use App\Domain\Instance\InstanceRepository;
use App\Domain\Instance\InstanceState;
use App\Fleet\Instances\InstanceHostnames;
use App\Fleet\Shell\CommandRunner;

/**
 * FLE-336: after the router's static configuration changed and it restarted, one real request through its HTTP entry point
 * (`127.0.0.1:8080`, `Host:` of a running instance) proves routing works, not only that the container is up. No running instance:
 * nothing to probe (the container and the API checks stand alone).
 */
final readonly class RouterProbe
{
    private const string URL = 'http://127.0.0.1:8080/';

    public function __construct(
        private InstanceRepository $instances,
        private InstanceHostnames $hostnames,
        private CommandRunner $runner,
    ) {
    }

    /** @return string|null null when routing works or there is nothing to probe, else why not */
    public function check(): ?string
    {
        foreach ($this->instances->all() as $instance) {
            if (InstanceState::Running !== $instance->state()) {
                continue;
            }
            try {
                $host = $this->hostnames->primary($instance->id());
            } catch (\Throwable) {
                return null;
            }
            try {
                $result = $this->runner->run(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '10', '-H', 'Host: '.$host, self::URL], null, [], null, 15.0);
            } catch (\Throwable $e) {
                return 'the probe through :8080 failed: '.$e->getMessage();
            }
            $code = trim($result->stdout);
            if (!$result->isSuccess() || 1 !== preg_match('/^[1-4]\d\d$/', $code) || '404' === $code) {
                return \sprintf('the probe through :8080 for %s answered "%s"', $host, '' === $code ? 'nothing' : $code);
            }

            return null;
        }

        return null;
    }
}
