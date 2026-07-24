from fleet.core import reboot
from fleet.core.runner import RunResult
from tests.conftest import FakeRunner


def test_read_reboot_status_absent_marker_is_not_pending(tmp_path):
    status = reboot.read_reboot_status(tmp_path / "nope", tmp_path / "nope.pkgs")
    assert status == reboot.RebootStatus(pending=False, since=None, packages=[])


def test_read_reboot_status_present_marker_is_pending_with_mtime(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    status = reboot.read_reboot_status(marker, tmp_path / "nope.pkgs")
    assert status.pending is True
    assert status.since == marker.stat().st_mtime
    assert status.packages == []


def test_read_reboot_status_reads_and_strips_package_lines(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    pkgs = tmp_path / "reboot-required.pkgs"
    pkgs.write_text("libc6\n linux-image-amd64 \n\nopenssl\n", encoding="utf-8")
    status = reboot.read_reboot_status(marker, pkgs)
    assert status.packages == ["libc6", "linux-image-amd64", "openssl"]


def test_read_reboot_status_missing_pkgs_file_yields_empty_list(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    status = reboot.read_reboot_status(marker, tmp_path / "does-not-exist.pkgs")
    assert status.packages == []


def test_format_duration_since_under_an_hour():
    assert reboot.format_duration_since(1000.0, now=1000.0 + 1800) == "<1h"


def test_format_duration_since_hours_only():
    assert reboot.format_duration_since(1000.0, now=1000.0 + 5 * 3600) == "5h"


def test_format_duration_since_days_and_hours():
    assert reboot.format_duration_since(0.0, now=3 * 86400 + 4 * 3600) == "3d 4h"


def test_state_roundtrips(tmp_path):
    path = tmp_path / "state.json"
    reboot._write_state(path, reboot.NotifyState(first_seen=1.0, last_notified=2.0))
    assert reboot._read_state(path) == reboot.NotifyState(first_seen=1.0, last_notified=2.0)


def test_state_missing_file_returns_none(tmp_path):
    assert reboot._read_state(tmp_path / "nope.json") is None


def test_state_corrupt_json_returns_none(tmp_path):
    path = tmp_path / "state.json"
    path.write_text("{not json", encoding="utf-8")
    assert reboot._read_state(path) is None


def test_clear_state_removes_file(tmp_path):
    path = tmp_path / "state.json"
    path.write_text("{}", encoding="utf-8")
    reboot._clear_state(path)
    assert not path.exists()


def test_clear_state_missing_file_is_a_noop(tmp_path):
    reboot._clear_state(tmp_path / "nope.json")  # must not raise


def test_compose_email_includes_hostname_duration_and_packages():
    status = reboot.RebootStatus(pending=True, since=0.0, packages=["libc6", "openssl"])
    subject, body = reboot.compose_email(status, hostname="ddev2", now=3600.0)
    assert "ddev2" in subject
    assert "libc6" in body and "openssl" in body
    assert "1h" in body


def test_compose_email_handles_unknown_packages():
    status = reboot.RebootStatus(pending=True, since=0.0, packages=[])
    _, body = reboot.compose_email(status, hostname="ddev2", now=10.0)
    assert "package list unavailable" in body


def test_send_email_returns_false_when_msmtprc_absent(tmp_path):
    sent = reboot.send_email(
        "subj",
        "body",
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=tmp_path / "nope",
    )
    assert sent is False


def test_send_email_invokes_msmtp_with_recipient_and_pipes_message(tmp_path):
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    fake = FakeRunner(default=RunResult(returncode=0, lines=[]))
    sent = reboot.send_email(
        "subj",
        "body text",
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        runner=fake,
    )
    assert sent is True
    assert fake.calls[0]["cmd"] == [
        "msmtp",
        "--file",
        str(msmtprc),
        "-a",
        "default",
        "ops@example.test",
    ]
    assert "body text" in fake.calls[0]["input_text"]


def test_send_email_returns_false_on_nonzero_exit(tmp_path):
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    fake = FakeRunner(default=RunResult(returncode=1, lines=["relay refused"]))
    sent = reboot.send_email(
        "subj",
        "body",
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        runner=fake,
    )
    assert sent is False


def _fake(rc=0):
    return FakeRunner(default=RunResult(returncode=rc, lines=[]))


def test_reboot_notify_not_pending_no_state_is_noop(tmp_path):
    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=tmp_path / "msmtprc",
        state_path=tmp_path / "state.json",
        marker=tmp_path / "reboot-required",
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=1000.0,
        runner=_fake(),
    )
    assert sent is False
    assert not (tmp_path / "state.json").exists()


def test_reboot_notify_not_pending_clears_stale_state(tmp_path):
    state_path = tmp_path / "state.json"
    reboot._write_state(state_path, reboot.NotifyState(first_seen=1.0, last_notified=1.0))
    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=tmp_path / "msmtprc",
        state_path=state_path,
        marker=tmp_path / "reboot-required",
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=1000.0,
        runner=_fake(),
    )
    assert sent is False
    assert not state_path.exists()


def test_reboot_notify_no_msmtprc_is_noop_even_when_pending(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=tmp_path / "nope",
        state_path=tmp_path / "state.json",
        marker=marker,
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=1000.0,
        runner=_fake(),
    )
    assert sent is False
    assert not (tmp_path / "state.json").exists()


def test_reboot_notify_first_transition_sends_and_writes_state(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    state_path = tmp_path / "state.json"
    fake = _fake()

    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        state_path=state_path,
        marker=marker,
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=1000.0,
        runner=fake,
    )
    assert sent is True
    state = reboot._read_state(state_path)
    assert state == reboot.NotifyState(first_seen=1000.0, last_notified=1000.0)


def test_reboot_notify_within_cadence_does_not_resend(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    state_path = tmp_path / "state.json"
    reboot._write_state(state_path, reboot.NotifyState(first_seen=1000.0, last_notified=1000.0))
    fake = _fake()

    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        state_path=state_path,
        marker=marker,
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=1000.0 + 3600,
        interval_hours=24,
        runner=fake,
    )
    assert sent is False
    assert fake.calls == []
    assert reboot._read_state(state_path) == reboot.NotifyState(1000.0, 1000.0)


def test_reboot_notify_past_cadence_resends_and_updates_last_notified(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    state_path = tmp_path / "state.json"
    reboot._write_state(state_path, reboot.NotifyState(first_seen=1000.0, last_notified=1000.0))
    fake = _fake()

    now = 1000.0 + 25 * 3600
    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        state_path=state_path,
        marker=marker,
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=now,
        interval_hours=24,
        runner=fake,
    )
    assert sent is True
    assert reboot._read_state(state_path) == reboot.NotifyState(1000.0, now)


def test_reboot_notify_one_second_before_interval_suppresses(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    state_path = tmp_path / "state.json"
    reboot._write_state(state_path, reboot.NotifyState(first_seen=1000.0, last_notified=1000.0))
    fake = _fake()

    now = 1000.0 + 24 * 3600 - 1
    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        state_path=state_path,
        marker=marker,
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=now,
        interval_hours=24,
        runner=fake,
    )
    assert sent is False
    assert fake.calls == []
    assert reboot._read_state(state_path) == reboot.NotifyState(1000.0, 1000.0)


def test_reboot_notify_exactly_at_interval_sends(tmp_path):
    marker = tmp_path / "reboot-required"
    marker.write_text("", encoding="utf-8")
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    state_path = tmp_path / "state.json"
    reboot._write_state(state_path, reboot.NotifyState(first_seen=1000.0, last_notified=1000.0))
    fake = _fake()

    now = 1000.0 + 24 * 3600
    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        state_path=state_path,
        marker=marker,
        pkgs_file=tmp_path / "reboot-required.pkgs",
        now=now,
        interval_hours=24,
        runner=fake,
    )
    assert sent is True
    assert len(fake.calls) == 1
    assert reboot._read_state(state_path) == reboot.NotifyState(1000.0, now)


def test_reboot_notify_test_mode_bypasses_pending_and_cadence(tmp_path):
    msmtprc = tmp_path / "msmtprc"
    msmtprc.write_text("account default\n", encoding="utf-8")
    fake = _fake()
    sent = reboot.reboot_notify(
        to_addr="ops@example.test",
        from_addr="fleet@example.test",
        msmtprc_path=msmtprc,
        state_path=tmp_path / "state.json",
        marker=tmp_path / "no-marker",
        pkgs_file=tmp_path / "no.pkgs",
        now=1000.0,
        test=True,
        runner=fake,
    )
    assert sent is True
    assert not (tmp_path / "state.json").exists()  # test send never touches cadence state
