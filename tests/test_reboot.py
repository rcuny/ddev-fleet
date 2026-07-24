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
