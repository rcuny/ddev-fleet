<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

use App\Fleet\Shell\CommandRunner;
use Psr\Clock\ClockInterface;

/**
 * Builds the TlsStatus: Caddy's journal of the last hour (rate limits, failed solves), the number of certificates it
 * issued in the last 7 days, and the expiry of the certificate served for the host's own name. Only reads: journalctl
 * and a TLS handshake with the local Caddy, no change to Caddy or to the host. Run by fleet-tls-check.timer.
 */
final readonly class TlsChecker
{
    public const int WINDOW_MINUTES = 60;
    public const int EXPIRY_WARNING_DAYS = 14;
    private const float JOURNAL_TIMEOUT = 60.0;
    private const string JOURNAL_FILTER = 'rateLimited|too many|solving challenges|challenge|clean ?up|acme|certificate obtained successfully';

    public function __construct(
        private CommandRunner $runner,
        private AcmeLogParser $parser,
        private CertificateProbe $probe,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $domain the host's own name (null before the host is initialised: nothing to probe)
     */
    public function check(?string $domain): TlsStatus
    {
        $now = $this->clock->now();
        $level = TlsLevel::Ok;
        $headlines = [];

        $events = $this->recent();
        if (null === $events) {
            $level = TlsLevel::Warning;
            $headlines[] = 'Caddy\'s journal could not be read, so certificate errors cannot be seen.';
            $events = new AcmeEvents();
        }

        $retryAfter = $events->retryAfter;
        if ($events->rateLimited > 0) {
            $active = null === $retryAfter || $retryAfter > $now;
            $level = $active ? TlsLevel::Error : $this->worse($level, TlsLevel::Warning);
            $what = null === $domain ? 'this host' : $domain;
            $headlines[] = $active
                ? \sprintf('Let\'s Encrypt is refusing new certificates for %s (rate limited)%s: new instances will have no HTTPS%s.', $what, self::since($events->rateLimitedSince), null === $retryAfter ? '' : ' until '.$retryAfter->format('Y-m-d H:i').' UTC')
                : \sprintf('Let\'s Encrypt rate limited %s earlier; the retry time has passed and Caddy is retrying.', $what);
        }
        if ($events->failedSolves > 0) {
            $level = $this->worse($level, TlsLevel::Warning);
            $headlines[] = \sprintf('%d certificate challenge%s failed in the last hour.', $events->failedSolves, 1 === $events->failedSolves ? '' : 's');
        }

        $certificates = [];
        if (null !== $domain) {
            $seen = $this->probe->probe($domain);
            if (null !== $seen) {
                $days = null === $seen->expiresAt ? null : (int) floor(($seen->expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
                $certificates[] = ['name' => $domain, 'expiresAt' => $seen->expiresAt?->format(\DATE_ATOM), 'daysLeft' => $days];
                if (null === $days) {
                    $level = $this->worse($level, TlsLevel::Warning);
                    $headlines[] = \sprintf('No usable certificate is served for %s.', $domain);
                } elseif ($days < 0) {
                    $level = TlsLevel::Error;
                    $headlines[] = \sprintf('The certificate of %s has expired.', $domain);
                } elseif ($days < self::EXPIRY_WARNING_DAYS) {
                    $level = $this->worse($level, TlsLevel::Warning);
                    $headlines[] = \sprintf('The certificate of %s expires in %d day%s.', $domain, $days, 1 === $days ? '' : 's');
                }
            }
        }

        return new TlsStatus(
            $level,
            $now,
            $domain,
            $headlines,
            $events->rateLimitedSince,
            $retryAfter,
            $events->rateLimited,
            $events->failedSolves,
            array_values(array_unique([...$events->rateLimitedNames, ...$events->failedSolveNames])),
            $events->errors,
            $certificates,
            $this->issuedLastWeek(),
        );
    }

    private function worse(TlsLevel $a, TlsLevel $b): TlsLevel
    {
        return $b->value > $a->value ? $b : $a;
    }

    private static function since(?\DateTimeImmutable $time): string
    {
        return null === $time ? '' : ' since '.$time->setTimezone(new \DateTimeZone('UTC'))->format('H:i').' UTC';
    }

    private function recent(): ?AcmeEvents
    {
        $lines = $this->journal(\sprintf('-%d min', self::WINDOW_MINUTES), self::JOURNAL_FILTER);

        return null === $lines ? null : $this->parser->parse($lines);
    }

    private function issuedLastWeek(): ?int
    {
        $lines = $this->journal('-7 days', 'certificate obtained successfully');

        return null === $lines ? null : $this->parser->parse($lines)->obtained;
    }

    /**
     * @return list<string>|null null when journalctl failed (not installed, no permission)
     */
    private function journal(string $since, string $filter): ?array
    {
        try {
            $result = $this->runner->run(
                ['journalctl', '-u', 'caddy', '--since', $since, '-o', 'json', '--no-pager', '-q', '-g', $filter],
                timeout: self::JOURNAL_TIMEOUT,
            );
        } catch (\Throwable) {
            return null;
        }
        // journalctl -g exits 1 when nothing matched.
        if (!$result->isSuccess() && '' !== trim($result->stderr)) {
            return null;
        }

        return preg_split('/\R/', $result->stdout, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
