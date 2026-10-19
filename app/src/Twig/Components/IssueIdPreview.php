<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Fleet\Projects\Rule\BranchName;
use App\Fleet\Projects\Rule\IssueIdPattern;
use App\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PreMount;

/**
 * The issue-id pattern tester on a project's General tab: the admin types a regular expression and a sample instance
 * label or branch and sees the id a deploy would extract, as they type.
 *
 * The pattern is operator input run by the server. Everything goes through {@see IssueIdPattern}: error() first, then
 * extract(), which runs under the backtrack limit and never throws. The samples are bounded here, before any matching,
 * and the inputs are the only writable props. Nothing the visitor typed is put into a message.
 *
 * Access has two layers, each enough on its own: `#[IsGranted]` on this class (Symfony's listener reads it from the
 * controller class the live route resolves to; the `^/_components` rules only require a signed-in user, so every live
 * component decides for itself, see ComponentsAccessTest) and the two hooks below, which also cover a mount outside a
 * request. This one is admin-only.
 */
#[AsLiveComponent]
#[IsGranted(ProjectVoter::SETTINGS)]
final class IssueIdPreview
{
    use DefaultActionTrait;

    public const string EMPTY = 'empty';
    public const string ERROR = 'error';
    public const string NONE = 'none';
    public const string MATCH = 'match';

    #[LiveProp(writable: true)]
    public string $pattern = '';

    #[LiveProp(writable: true)]
    public string $label = '';

    #[LiveProp(writable: true)]
    public string $branch = '';

    public function __construct(private readonly AuthorizationCheckerInterface $authorization)
    {
    }

    /**
     * Runs when the component is mounted by a template (first render).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[PreMount]
    public function checkOnMount(array $data): array
    {
        $this->denyUnlessAllowed();

        return $data;
    }

    /** Runs on every request the browser sends back (re-render, GET with props). */
    #[PostHydrate]
    public function checkOnHydrate(): void
    {
        $this->denyUnlessAllowed();
    }

    /**
     * @return array{state: 'empty'|'error'|'none'|'match', message?: string, issueId?: string}
     */
    public function result(): array
    {
        if ('' === $this->pattern) {
            return ['state' => self::EMPTY, 'message' => 'Enter a pattern to test.'];
        }
        if (null !== $error = IssueIdPattern::error($this->pattern)) {
            return ['state' => self::ERROR, 'message' => $error];
        }
        if ('' === $this->label && '' === $this->branch) {
            return ['state' => self::EMPTY, 'message' => 'Enter a sample instance label or branch.'];
        }
        if (\strlen($this->label) > IssueIdPattern::LABEL_MAX) {
            return ['state' => self::ERROR, 'message' => \sprintf('The label must be at most %d characters.', IssueIdPattern::LABEL_MAX)];
        }
        if (\strlen($this->branch) > BranchName::MAX) {
            return ['state' => self::ERROR, 'message' => \sprintf('The branch name must be at most %d characters.', BranchName::MAX)];
        }

        $issueId = IssueIdPattern::extract($this->pattern, $this->label, $this->branch);
        if (null === $issueId) {
            return ['state' => self::NONE, 'message' => 'No issue id found in these samples.'];
        }

        return ['state' => self::MATCH, 'issueId' => $issueId];
    }

    private function denyUnlessAllowed(): void
    {
        if (!$this->authorization->isGranted(ProjectVoter::SETTINGS)) {
            throw new AccessDeniedException('Only administrators may test issue id patterns.');
        }
    }
}
