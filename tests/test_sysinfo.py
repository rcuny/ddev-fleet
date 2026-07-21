import shutil

from fleet.core import sysinfo
from fleet.core.sysinfo import SystemStats

_SAMPLE_MEMINFO = """\
MemTotal:       131923972 kB
MemFree:         2048000 kB
MemAvailable:   100663296 kB
Buffers:          123456 kB
"""


def test_parse_meminfo_converts_kb_to_bytes():
    avail, total = sysinfo._parse_meminfo(_SAMPLE_MEMINFO)
    assert avail == 100663296 * 1024
    assert total == 131923972 * 1024


def test_parse_meminfo_missing_keys_yield_none():
    avail, total = sysinfo._parse_meminfo("Buffers: 123 kB\n")
    assert avail is None
    assert total is None


def test_read_meminfo_missing_file_returns_none(tmp_path):
    avail, total = sysinfo._read_meminfo(tmp_path / "nope")
    assert (avail, total) == (None, None)


def test_gather_reports_disk_of_instances_mount(tmp_path):
    instances = tmp_path / "instances"
    instances.mkdir()
    stats = SystemStats.gather(instances, meminfo_path=tmp_path / "nope")

    usage = shutil.disk_usage(instances)
    assert stats.disk_total_bytes == usage.total
    assert stats.disk_free_bytes > 0
    assert stats.mount == str(instances)
    # meminfo path missing -> memory is n/a, not a crash
    assert stats.mem_available_bytes is None


def test_gather_reads_memory_from_meminfo(tmp_path):
    instances = tmp_path / "instances"
    instances.mkdir()
    meminfo = tmp_path / "meminfo"
    meminfo.write_text(_SAMPLE_MEMINFO, encoding="utf-8")

    stats = SystemStats.gather(instances, meminfo_path=meminfo)
    assert stats.mem_available_bytes == 100663296 * 1024
    assert stats.mem_total_bytes == 131923972 * 1024


def test_fmt_bytes():
    assert sysinfo.fmt_bytes(None) == "n/a"
    assert sysinfo.fmt_bytes(0) == "0 B"
    assert sysinfo.fmt_bytes(512) == "512 B"
    assert sysinfo.fmt_bytes(1536) == "1.5 KiB"
    assert sysinfo.fmt_bytes(96 * 1024**3) == "96.0 GiB"


def test_display_returns_formatted_strings(tmp_path):
    instances = tmp_path / "instances"
    instances.mkdir()
    meminfo = tmp_path / "meminfo"
    meminfo.write_text(_SAMPLE_MEMINFO, encoding="utf-8")

    display = SystemStats.gather(instances, meminfo_path=meminfo).display()
    assert display["mem_free"] == "96.0 GiB"
    assert display["mem_total"].endswith("GiB")
    assert display["disk_free"].endswith(("GiB", "TiB", "MiB"))
    assert display["mount"] == str(instances)
