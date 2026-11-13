<?php

declare(strict_types=1);

namespace App\Fleet\Isolation;

use App\Fleet\Shell\CommandRunner;
use App\Fleet\System\ApplyTarget;
use App\Fleet\System\PrivilegedApply;
use App\Fleet\System\TargetSupport;

/**
 * FLE-299: reads the live table through the root helper (`apply isolation` with nothing staged prints `nft -j list table`).
 * Returns null when the helper cannot be asked (an older helper, no sudo): the callers then change nothing.
 */
final readonly class IsolationReport
{
    public function __construct(private CommandRunner $runner, private ?TargetSupport $support = null)
    {
    }

    /**
     * @return array{installed: bool, flows: list<array{0: string, 1: string, 2: int}>, host: list<array{0: string, 1: int}>, routers: list<string>}|null
     */
    public function read(): ?array
    {
        if (null !== $this->support && !$this->support->supports(ApplyTarget::Isolation)) {
            return null;
        }
        try {
            $result = $this->runner->run(['sudo', '-n', '--', PrivilegedApply::HELPER, ApplyTarget::Isolation->value], null, [], null, 30.0);
        } catch (\Throwable) {
            return null;
        }
        if (!$result->isSuccess()) {
            return null;
        }

        return self::parse($result->stdout);
    }

    /**
     * @return array{installed: bool, flows: list<array{0: string, 1: string, 2: int}>, host: list<array{0: string, 1: int}>, routers: list<string>}|null
     */
    public static function parse(string $json): ?array
    {
        $doc = json_decode($json, true);
        if (!\is_array($doc) || !isset($doc['nftables']) || !\is_array($doc['nftables'])) {
            return null;
        }
        $installed = false;
        $flows = [];
        $host = [];
        $routers = [];
        foreach ($doc['nftables'] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (isset($item['table']['name']) && IsolationRuleset::TABLE === $item['table']['name']) {
                $installed = true;
            }
            $set = $item['set'] ?? null;
            if (!\is_array($set) || !isset($set['name'], $set['elem']) || !\is_array($set['elem'])) {
                continue;
            }
            foreach ($set['elem'] as $elem) {
                $val = \is_array($elem) && isset($elem['elem']['val']) ? $elem['elem']['val'] : $elem;
                $parts = \is_array($val) && isset($val['concat']) ? $val['concat'] : (\is_array($val) ? null : [$val]);
                if (!\is_array($parts)) {
                    continue;
                }
                match ($set['name']) {
                    'observed' => 3 === \count($parts) && \is_string($parts[0]) && \is_string($parts[1]) && is_numeric($parts[2]) ? $flows[] = [$parts[0], $parts[1], (int) $parts[2]] : null,
                    'observed_host' => 2 === \count($parts) && \is_string($parts[0]) && is_numeric($parts[1]) ? $host[] = [$parts[0], (int) $parts[1]] : null,
                    'routers' => 1 === \count($parts) && \is_string($parts[0]) ? $routers[] = $parts[0] : null,
                    default => null,
                };
            }
        }

        return ['installed' => $installed, 'flows' => $flows, 'host' => $host, 'routers' => $routers];
    }
}
