<?php

declare(strict_types=1);

namespace App\Fleet\Upgrade;

use App\Fleet\Shell\CommandRunner;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The CHANGELOG at a release tag (so it is the text that will be installed) and the sections between two versions.
 * GitHub sources are read from raw.githubusercontent.com over HTTPS, capped at 2 MiB; a local repository (rehearsals,
 * tests) is read with `git show`. Any failure is an UpgradeSourceUnavailable.
 */
final readonly class ChangelogReader
{
    public const int MAX_BYTES = 2 * 1024 * 1024;
    public const float TIMEOUT_SECONDS = 15.0;

    public function __construct(
        private HttpClientInterface $http,
        private CommandRunner $runner,
        private RepoUrl $repo,
        private ChangelogParser $parser = new ChangelogParser(),
    ) {
    }

    /**
     * The sections newer than $from up to and including $to, newest first, read from the changelog at $to. When $from
     * is null (a branch install) only the section of $to is returned.
     *
     * @return list<ChangelogSection>
     *
     * @throws UpgradeSourceUnavailable
     */
    public function between(?Version $from, Version $to): array
    {
        return $this->filter($this->parser->parse($this->fetch($to)), $from, $to);
    }

    /**
     * @param list<ChangelogSection> $sections
     *
     * @return list<ChangelogSection>
     */
    public function filter(array $sections, ?Version $from, Version $to): array
    {
        $picked = array_values(array_filter($sections, static function (ChangelogSection $s) use ($from, $to): bool {
            if (null === $from) {
                return 0 === $s->version->compare($to);
            }

            return $s->version->isNewerThan($from) && $s->version->compare($to) <= 0;
        }));
        usort($picked, static fn (ChangelogSection $a, ChangelogSection $b): int => $b->version->compare($a->version));

        return $picked;
    }

    /**
     * @throws UpgradeSourceUnavailable
     */
    public function fetch(Version $at): string
    {
        $tag = $at->tag();
        $slug = $this->repo->githubSlug();
        if (null !== $slug) {
            return $this->fetchHttp(\sprintf('https://raw.githubusercontent.com/%s/%s/CHANGELOG.md', $slug, $tag));
        }

        return $this->fetchGit($tag);
    }

    private function fetchHttp(string $url): string
    {
        try {
            $response = $this->http->request('GET', $url, ['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => 30.0]);
            if (200 !== $response->getStatusCode()) {
                throw new UpgradeSourceUnavailable(\sprintf('fetching the changelog answered HTTP %d', $response->getStatusCode()));
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (\strlen($body) > self::MAX_BYTES) {
                    $response->cancel();
                    throw new UpgradeSourceUnavailable('the changelog is larger than 2 MiB');
                }
            }

            return $body;
        } catch (UpgradeSourceUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UpgradeSourceUnavailable('fetching the changelog failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function fetchGit(string $tag): string
    {
        $path = $this->repo->localPath();
        if (null === $path) {
            throw new UpgradeSourceUnavailable('the changelog of a non-GitHub source can only be read from a local repository');
        }
        try {
            $result = $this->runner->run(['git', '--git-dir='.$path, 'show', $tag.':CHANGELOG.md'], env: ['GIT_TERMINAL_PROMPT' => '0'], timeout: self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            throw new UpgradeSourceUnavailable('reading the changelog failed: '.$e->getMessage(), 0, $e);
        }
        if (!$result->isSuccess()) {
            throw new UpgradeSourceUnavailable(\sprintf('git show %s:CHANGELOG.md exited %d', $tag, $result->exitCode));
        }
        if (\strlen($result->stdout) > self::MAX_BYTES) {
            throw new UpgradeSourceUnavailable('the changelog is larger than 2 MiB');
        }

        return $result->stdout;
    }
}
