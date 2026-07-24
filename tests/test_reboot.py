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
