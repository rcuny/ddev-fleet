from fleet.core import reboot


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
