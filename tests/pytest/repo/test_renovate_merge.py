"""Tests for scripts/renovate_merge.py (FLE-11): decision table, acceptance, full runs.

The script is standalone (stdlib only, run by a pipeline), so it is loaded by path.
A FakeClient stands in for the Bitbucket API: no network.
"""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

import pytest

SCRIPT = Path(__file__).resolve().parents[3] / "scripts" / "renovate_merge.py"
spec = importlib.util.spec_from_file_location("renovate_merge", SCRIPT)
rm = importlib.util.module_from_spec(spec)
sys.modules["renovate_merge"] = rm  # dataclasses looks the module up by name
spec.loader.exec_module(rm)

REPO = "ws/repo"
ME = "acct-me"
DEV = "d" * 40


def head(n: int) -> str:
    return f"{n:012x}" + "0" * 28  # full 40-char hash; first 12 chars identify it


class FakeClient:
    """Serves canned Bitbucket responses and records every POST."""

    def __init__(self, develop: str = DEV):
        self.develop = develop
        self.moved_develop: str | None = None  # returned from the 2nd branch read on
        self.branch_reads = 0
        self.prs: dict[int, dict] = {}
        self.posts: list[tuple[str, dict]] = []
        self.merge_response = rm.Response(200, {})
        self.task_states: list[str] = []
        self.pages: dict[str, list[dict]] = {}  # path -> values (served one per page)
        self.user = ME

    def add_pr(
        self,
        pr_id,
        *,
        branch="renovate/x",
        approved_by=(),
        comments=(),
        statuses=("SUCCESSFUL",),
        up_to_date=True,
        short=True,
        state="OPEN",
    ):
        full = head(pr_id)
        self.prs[pr_id] = {
            "branch": branch,
            "full": full,
            "short": full[:12] if short else full,
            "participants": [{"user": {"account_id": a}, "approved": True} for a in approved_by],
            "comments": list(comments),
            "statuses": [{"state": s} for s in statuses],
            "merge_base": self.develop if up_to_date else "o" * 40,
            "state": state,
        }

    # -- the client interface used by the script
    def get(self, path, params=None):
        if path == "/user":
            return {"account_id": self.user}
        if path == f"/repositories/{REPO}/refs/branches/develop":
            self.branch_reads += 1
            moved = self.moved_develop and self.branch_reads > 1
            return {"target": {"hash": self.moved_develop if moved else self.develop}}
        if path.startswith("http"):  # task-status poll
            return {"task_status": self.task_states.pop(0)}
        base = f"/repositories/{REPO}/"
        if path.startswith(base + "pullrequests/"):
            pr = self.prs[int(path.rsplit("/", 1)[1])]
            return {"participants": pr["participants"]}
        if path.startswith(base + "commit/"):
            pr = self._by_head(path.split("/")[-1])
            return {"hash": pr["full"]}
        if path.startswith(base + "merge-base/"):
            return {"hash": self._by_head(path.split("..")[1])["merge_base"]}
        raise AssertionError(f"unexpected GET {path}")

    def paginate(self, path, params=None):
        if path in self.pages:
            return self.pages[path]
        base = f"/repositories/{REPO}/"
        if path == base + "pullrequests":
            assert "state" not in params  # ignored by Bitbucket next to q
            assert params["q"] == 'state="OPEN" AND destination.branch.name="develop"'
            return [
                {
                    "id": i,
                    "state": pr["state"],
                    "source": {
                        "branch": {"name": pr["branch"]},
                        "commit": {"hash": pr["short"]},
                    },
                }
                for i, pr in self.prs.items()
            ]
        if path.endswith("/comments"):
            return self.prs[int(path.split("/")[-2])]["comments"]
        if path.endswith("/statuses"):
            return self._by_head(path.split("/")[-2])["statuses"]
        raise AssertionError(f"unexpected paginate {path}")

    def post(self, path, body):
        self.posts.append((path, body))
        return self.merge_response if path.endswith("/merge") else rm.Response(201, {})

    def _by_head(self, h):
        return next(pr for pr in self.prs.values() if pr["full"].startswith(h[:12]))

    @property
    def merges(self):
        return [p for p, _ in self.posts if p.endswith("/merge")]

    @property
    def comment_posts(self):
        return [b for p, b in self.posts if p.endswith("/comments")]


def comment(raw, account=ME, deleted=False):
    return {"user": {"account_id": account}, "content": {"raw": raw}, "deleted": deleted}


def run(client, tmp_path=None, **kw):
    lines: list[str] = []
    kw.setdefault("approvers", {ME})
    flag = str(tmp_path / "flag") if tmp_path else None
    merged = rm.run(
        client,
        REPO,
        base="develop",
        prefix="renovate/",
        renovate_flag=flag,
        sleep=lambda s: None,
        out=lines.append,
        **kw,
    )
    return merged, lines


# --- decision table ----------------------------------------------------------


@pytest.mark.parametrize(
    "accepted, statuses, up_to_date, verdict",
    [
        (False, ["SUCCESSFUL"], True, "skip"),
        (False, ["FAILED"], True, "skip"),
        (True, ["SUCCESSFUL"], True, "merge"),
        (True, ["SUCCESSFUL", "SUCCESSFUL"], True, "merge"),
        (True, ["SUCCESSFUL", "FAILED"], True, "red"),
        (True, ["STOPPED"], True, "red"),
        (True, ["FAILED"], False, "wait"),  # behind beats red: Renovate recreates it
        (True, ["STOPPED"], False, "wait"),
        (True, ["INPROGRESS"], False, "wait"),
        (True, [], False, "wait"),
        (True, ["SUCCESSFUL", "INPROGRESS"], True, "wait"),
        (True, ["INPROGRESS"], True, "wait"),
        (True, [], True, "wait"),
        (True, ["SUCCESSFUL"], False, "wait"),
    ],
)
def test_decide_table(accepted, statuses, up_to_date, verdict):
    assert rm.decide(accepted, statuses, up_to_date)[0] == verdict


def test_decide_reasons_are_human_readable():
    assert "behind" in rm.decide(True, ["SUCCESSFUL"], False)[1]
    assert "no commit status" in rm.decide(True, [], True)[1]
    assert "FAILED" in rm.decide(True, ["FAILED"], True)[1]
    assert "behind" in rm.decide(True, ["FAILED"], False)[1]


# --- acceptance --------------------------------------------------------------


def test_approve_by_allowed_user_counts_other_user_does_not():
    pr = {"participants": [{"user": {"account_id": "other"}, "approved": True}]}
    assert rm.acceptance(pr, [], {ME}) == (False, "")
    pr["participants"].append({"user": {"account_id": ME}, "approved": True})
    assert rm.acceptance(pr, [], {ME}) == (True, "approved")


def test_participant_who_has_not_approved_does_not_count():
    pr = {"participants": [{"user": {"account_id": ME}, "approved": False}]}
    assert rm.acceptance(pr, [], {ME})[0] is False


@pytest.mark.parametrize(
    "raw, ok",
    [
        ("/merge", True),
        ("  /MERGE  ", True),
        ("/Merge", True),
        ("looks good\n/merge\nthanks", True),
        ("looks good\r\n/merge\r\n", True),
        ("/merge please", False),
        ("do not /merge", False),
        ("/merged", False),
        ("", False),
    ],
)
def test_merge_comment_variants(raw, ok):
    assert rm.acceptance({}, [comment(raw)], {ME})[0] is ok


def test_merge_comment_from_other_user_or_deleted_does_not_count():
    assert rm.acceptance({}, [comment("/merge", account="other")], {ME})[0] is False
    assert rm.acceptance({}, [comment("/merge", deleted=True)], {ME})[0] is False
    ok = rm.acceptance({}, [comment("/merge", deleted=True), comment("/merge")], {ME})
    assert ok == (True, "/merge comment")


# --- approvers / client / pagination ----------------------------------------


def test_approvers_default_to_the_token_account_and_env_overrides():
    client = FakeClient()
    assert rm.resolve_approvers(client, "") == {ME}
    assert rm.resolve_approvers(client, " a1, ,b2 ") == {"a1", "b2"}


def test_paginate_follows_next_links():
    pages = {
        "https://api.bitbucket.org/2.0/x?pagelen=2": {"values": [1, 2], "next": "N2"},
        "N2": {"values": [3], "next": "N3"},
        "N3": {"values": [4]},
    }
    client = rm.BitbucketClient("u", "p")
    seen = []
    client.get = lambda path, params=None: (
        seen.append(path),
        pages[path if path in pages else "https://api.bitbucket.org/2.0/x?pagelen=2"],
    )[1]
    assert client.paginate("/x", {"pagelen": 2}) == [1, 2, 3, 4]
    assert seen[1:] == ["N2", "N3"]


def test_client_never_sends_the_token_to_foreign_urls():
    client = rm.BitbucketClient("u", "p")
    with pytest.raises(rm.ApiError):
        client.get("https://evil.example/2.0/x")


def test_client_never_follows_redirects():
    # urllib would replay the Authorization header to the redirect target
    handler = rm._NoRedirect()
    assert handler.redirect_request(None, None, 302, "Found", {}, "https://evil.example/") is None
    client = rm.BitbucketClient("u", "p")
    assert any(isinstance(h, rm._NoRedirect) for h in client._opener.handlers)


def test_candidates_are_filtered_by_prefix_and_sorted_oldest_first():
    client = FakeClient()
    client.add_pr(9, branch="renovate/b")
    client.add_pr(3, branch="feature/human")
    client.add_pr(5, branch="renovate/a")
    got = rm.candidate_prs(client, REPO, "develop", "renovate/")
    assert [p["id"] for p in got] == [5, 9]


def test_candidates_drop_merged_and_declined_prs_even_if_the_api_returns_them():
    # Live 2026-10-07 (#71): with `q`, Bitbucket ignored state=OPEN and listed
    # merged/declined renovate/* PRs, which then showed up as "behind, wait".
    client = FakeClient()
    client.add_pr(3, state="MERGED", approved_by=[ME])
    client.add_pr(11, state="DECLINED", approved_by=[ME])
    client.add_pr(22, approved_by=[ME])
    got = rm.candidate_prs(client, REPO, "develop", "renovate/")
    assert [p["id"] for p in got] == [22]


# --- full runs ---------------------------------------------------------------


def test_merges_only_the_first_eligible_pr_and_writes_the_flag(tmp_path):
    client = FakeClient()
    client.add_pr(4, approved_by=[])  # not approved
    client.add_pr(5, approved_by=[ME])
    client.add_pr(6, approved_by=[ME])
    merged, lines = run(client, tmp_path)
    assert merged == 5
    assert client.merges == [f"/repositories/{REPO}/pullrequests/5/merge"]
    body = client.posts[0][1]
    assert body == {
        "type": "pullrequest",
        "merge_strategy": "merge_commit",
        "close_source_branch": True,
    }
    assert (tmp_path / "flag").read_text() == "merged #5\n"
    assert lines[0].startswith("#4 renovate/x skip:")
    assert lines[1].startswith("#5 renovate/x merge: merged")
    assert lines[2] == "#6 renovate/x wait: deferred, #5 was merged this run"
    assert lines[-1] == "summary: 3 candidate(s), merged #5; Renovate requested (merged #5)"


def test_merge_comment_is_enough_and_full_hashes_skip_the_commit_lookup(tmp_path):
    client = FakeClient()
    client.add_pr(7, comments=[comment("/merge")], short=False)
    merged, _ = run(client, tmp_path)
    assert merged == 7


def test_gates_still_running_requests_no_renovate_run(tmp_path):
    client = FakeClient()
    client.add_pr(5, approved_by=[ME], statuses=["INPROGRESS"])
    client.add_pr(6, approved_by=[ME], statuses=[])
    merged, lines = run(client, tmp_path)
    assert merged is None and client.posts == []
    assert not (tmp_path / "flag").exists()
    assert "gates still running" in lines[0] and "no commit status" in lines[1]
    assert lines[-1] == "summary: 2 candidate(s), nothing merged"


def test_accepted_pr_behind_develop_requests_renovate_without_a_merge(tmp_path):
    client = FakeClient()
    client.add_pr(5, approved_by=[ME], statuses=["INPROGRESS"])  # no reason to run Renovate
    client.add_pr(6, approved_by=[ME], up_to_date=False)
    client.add_pr(7, comments=[comment("/merge")], up_to_date=False, statuses=["FAILED"])
    merged, lines = run(client, tmp_path)
    assert merged is None and client.posts == []
    assert (tmp_path / "flag").read_text() == "behind #6, #7\n"
    assert "gates still running" in lines[0]
    assert "wait: behind" in lines[1] and "wait: behind" in lines[2]
    assert lines[-1] == (
        "summary: 3 candidate(s), nothing merged; Renovate requested (behind #6, #7)"
    )


def test_behind_but_not_accepted_or_red_requests_nothing(tmp_path):
    client = FakeClient()
    client.add_pr(4, approved_by=[], up_to_date=False)  # not accepted
    client.add_pr(5, approved_by=[ME], statuses=["FAILED"])  # red and up to date
    merged, lines = run(client, tmp_path)
    assert merged is None
    assert not (tmp_path / "flag").exists()
    assert lines[0].startswith("#4 renovate/x skip:") and lines[1].startswith("#5 renovate/x red:")
    (posted,) = client.comment_posts  # red on an up-to-date head still needs a human
    assert "needs a human" in posted["content"]["raw"]


def test_red_and_behind_waits_without_a_human_comment(tmp_path):
    client = FakeClient()
    client.add_pr(5, approved_by=[ME], statuses=["FAILED"], up_to_date=False)
    _, lines = run(client, tmp_path)
    assert client.posts == []
    assert lines[0].startswith("#5 renovate/x wait: behind")
    assert (tmp_path / "flag").read_text() == "behind #5\n"


def test_a_merge_takes_precedence_over_behind_in_the_flag(tmp_path):
    client = FakeClient()
    client.add_pr(4, approved_by=[ME], up_to_date=False)
    client.add_pr(5, approved_by=[ME])
    merged, _ = run(client, tmp_path)
    assert merged == 5
    assert (tmp_path / "flag").read_text() == "merged #5\n"


def test_dry_run_writes_no_renovate_flag(tmp_path):
    client = FakeClient()
    client.add_pr(6, approved_by=[ME], up_to_date=False)
    _, lines = run(client, tmp_path, dry_run=True)
    assert not (tmp_path / "flag").exists()
    assert lines[-1].endswith("Renovate would be requested (behind #6)")


def test_a_red_pr_does_not_block_a_later_green_one(tmp_path):
    client = FakeClient()
    client.add_pr(4, approved_by=[ME], statuses=["FAILED"])
    client.add_pr(5, approved_by=[ME])
    merged, _ = run(client, tmp_path)
    assert merged == 5


def test_moved_develop_aborts_the_merge(tmp_path):
    client = FakeClient()
    client.moved_develop = "e" * 40
    client.add_pr(5, approved_by=[ME])
    merged, lines = run(client, tmp_path)
    assert merged is None and client.merges == []
    # the PR is behind the new head now: Renovate must recreate it
    assert (tmp_path / "flag").read_text() == "behind #5\n"
    assert lines[0].startswith("#5 renovate/x wait: develop moved")


def test_red_approved_pr_gets_exactly_one_comment_per_head():
    client = FakeClient()
    client.add_pr(5, approved_by=[ME], statuses=["FAILED"])
    _, lines = run(client)
    (posted,) = client.comment_posts
    assert posted["content"]["raw"] == (
        f"[renovate-merge] Gates are red on {head(5)[:12]}; this approved PR needs a human."
    )
    assert lines[0].startswith("#5 renovate/x red:") and lines[0].endswith("; commented")
    # second run: the marker is now among the comments -> no duplicate
    client.prs[5]["comments"].append(comment(posted["content"]["raw"]))
    client.posts.clear()
    _, lines = run(client)
    assert client.posts == [] and lines[0].endswith("; already commented")


def test_a_deleted_marker_comment_or_a_new_head_gets_a_new_comment():
    client = FakeClient()
    client.add_pr(5, approved_by=[ME], statuses=["FAILED"])
    old = f"[renovate-merge] Gates are red on {'f' * 12}; this approved PR needs a human."
    client.prs[5]["comments"] = [comment(old)]  # marker for a different (older) head
    run(client)
    assert len(client.comment_posts) == 1
    client.posts.clear()
    client.prs[5]["comments"] = [
        comment(f"[renovate-merge] Gates are red on {head(5)[:12]}", deleted=True)
    ]
    run(client)
    assert len(client.comment_posts) == 1


def test_dry_run_never_posts(tmp_path):
    client = FakeClient()
    client.add_pr(4, approved_by=[ME], statuses=["FAILED"])
    client.add_pr(5, approved_by=[ME])
    merged, lines = run(client, tmp_path, dry_run=True)
    assert client.posts == []
    assert not (tmp_path / "flag").exists()
    assert "would comment" in lines[0]
    assert "dry-run, not merged" in lines[1]
    assert lines[-1] == (
        "summary: 2 candidate(s), would merge #5; Renovate would be requested (merged #5)"
    )


def test_202_merge_task_is_polled_until_success(tmp_path):
    client = FakeClient()
    client.add_pr(5, approved_by=[ME])
    client.merge_response = rm.Response(202, {}, "https://api.bitbucket.org/2.0/task/1")
    client.task_states = ["PENDING", "PENDING", "SUCCESS"]
    merged, _ = run(client, tmp_path)
    assert merged == 5 and client.task_states == []
    assert (tmp_path / "flag").read_text() == "merged #5\n"


def test_202_task_link_can_come_from_the_body():
    client = FakeClient()
    client.add_pr(5, approved_by=[ME])
    link = "https://api.bitbucket.org/2.0/task/2"
    client.merge_response = rm.Response(202, {"links": {"self": {"href": link}}})
    client.task_states = ["SUCCESS"]
    assert run(client)[0] == 5


def test_failed_or_endless_merge_task_raises_and_writes_no_flag(tmp_path):
    client = FakeClient()
    client.add_pr(5, approved_by=[ME])
    client.merge_response = rm.Response(202, {}, "https://api.bitbucket.org/2.0/task/1")
    client.task_states = ["FAILED"]
    with pytest.raises(rm.MergeError):
        run(client, tmp_path)
    assert not (tmp_path / "flag").exists()
    client.task_states = ["PENDING"] * 50
    with pytest.raises(rm.MergeError, match="still pending"):
        run(client, tmp_path)


# --- main() ------------------------------------------------------------------


def test_missing_credentials_or_repo_exit_1_with_a_clear_message(capsys):
    assert rm.main([], env={}) == 1
    err = capsys.readouterr().err
    assert "RENOVATE_USERNAME" in err and "RENOVATE_PASSWORD" in err
    assert "BITBUCKET_REPO_FULL_NAME" in err
    env = {"RENOVATE_USERNAME": "me@x", "RENOVATE_PASSWORD": "s3cret"}
    assert rm.main([], env=env) == 1
    err = capsys.readouterr().err
    assert "BITBUCKET_REPO_FULL_NAME" in err and "s3cret" not in err


def test_main_runs_with_an_injected_client_and_returns_0(tmp_path, capsys):
    client = FakeClient()
    client.add_pr(5, approved_by=[ME])
    flag = tmp_path / "flag"
    env = {"BITBUCKET_REPO_FULL_NAME": REPO}
    argv = ["--renovate-flag", str(flag)]
    assert rm.main(argv, env=env, client=client, sleep=lambda s: None) == 0
    assert flag.read_text() == "merged #5\n"
    assert "summary:" in capsys.readouterr().out


def test_main_uses_the_approvers_env_instead_of_the_token_account(tmp_path):
    client = FakeClient()
    client.add_pr(5, approved_by=[ME])
    env = {"BITBUCKET_REPO_FULL_NAME": REPO, "RENOVATE_MERGE_APPROVERS": "someone-else"}
    assert rm.main([], env=env, client=client) == 0
    assert client.posts == []


def test_main_reports_api_errors_without_a_traceback(capsys):
    class Boom(FakeClient):
        def get(self, path, params=None):
            raise rm.ApiError("GET /user -> HTTP 401: bad credentials")

    assert rm.main([], env={"BITBUCKET_REPO_FULL_NAME": REPO}, client=Boom()) == 1
    assert "HTTP 401" in capsys.readouterr().err
