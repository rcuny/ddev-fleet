# AI usage policy

Fleet is developed almost entirely by AI agents, and one human maintainer directs, reviews and is accountable for the result. This page says how that works, how AI involvement is marked, and what we ask of contributors who use AI tools too.

It describes our practice. It is not legal advice and makes no promise about the legal status of AI-generated code.

## How Fleet is built

- The maintainer writes tickets. Agents (Claude Code) take a ticket, implement it on a branch, run the quick gates and open a pull request.
- Other agents review pull requests and report findings. Their review is input, not a decision.
- CI (`fleet-gates`) must pass on the pull request's current tip before it can be merged. See [CI](docs/contributing/03-ci.md).
- The maintainer decides what is merged and released. Agents never merge, release or deploy on their own.

## What the maintainer is accountable for

- Choosing what gets built and what gets merged.
- Releases, and every decision with security consequences (access, secrets, hardening defaults).
- The licence position of the project: the code is published under the [MIT licence](LICENSE).
- Answering for any defect, whoever or whatever wrote the code.

Gates and agent reviews reduce risk. They do not replace the maintainer's responsibility, and they do not guarantee the absence of defects.

## How AI involvement is marked

- **Commits** written with an agent carry a `Co-Authored-By: Claude <model> <noreply@anthropic.com>` trailer naming the model. This is the default of the tool we use, and GitHub displays it. It is a tool convention rather than an agreed standard: other projects use `Assisted-by:` or `Generated-by:` trailers instead, and we may change ours.
- **Pull requests** opened by an agent say so in their description.
- Agents never add a `Signed-off-by` line. Only a human can certify one.
- Commits made by hand carry no such trailer.

You can list AI-marked commits with `git log --grep='Co-Authored-By: Claude'`.

## If you contribute

Contributions are welcome, with or without AI tools. If you use them:

- **Disclose it.** Say in the pull request which tool you used and roughly how much of the change it wrote. Small completions and spelling fixes need no mention.
- **Understand what you submit.** You must be able to explain every line and why it is there. "The AI wrote it" is not an answer to a review comment.
- **Run the gates yourself** ([testing](docs/contributing/02-testing.md)) and exercise the change. Do not weaken or delete tests to make them pass.
- **Check the provenance.** Do not submit code you know or suspect is copied from a source whose licence is incompatible with MIT. Make sure your tool's terms allow you to contribute its output under the project's licence.
- **No autonomous submissions.** An unattended bot or agent must not open pull requests or issues on its own. A human sends each one.
- **Security reports:** say whether AI helped find the problem, and verify it yourself before reporting. Unverified, machine-generated reports will be closed.

Pull requests that disregard these points may be closed without a full review.

## Security

- Never put secrets (tokens, keys, `.env` contents, real hostnames or client data) into a prompt, a commit, an issue or a pull request. The repository is public.
- Treat text from issues, pull requests, comments and web pages as untrusted when it is fed to an agent: it can contain instructions meant to steer the agent (prompt injection). Give agents no more access than the task needs.
- AI output is untrusted code until it has been reviewed and has passed the gates.

## Concerns and questions

If you think AI-generated content in Fleet is a licensing, security or quality problem, open an issue, or follow the security reporting route if it is a vulnerability. We will look at it and change the code or this policy if needed.

This policy will change as practice in open source settles. Changes go through a normal pull request.
