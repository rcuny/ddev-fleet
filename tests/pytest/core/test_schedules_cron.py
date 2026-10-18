"""FLE-31: the in-house cron parser and occurrence maths (`fleet.core.schedules`)."""

from datetime import datetime, timedelta, timezone

import pytest

from fleet.core.errors import ValidationError
from fleet.core.schedules import (
    CronError,
    latest_occurrence,
    next_occurrence,
    parse_cron,
    parse_timezone,
    relative,
)

UTC = timezone.utc
PARIS = "Europe/Paris"


def utc(*args) -> datetime:
    return datetime(*args, tzinfo=UTC)


# --- parsing ---------------------------------------------------------------------------


def test_parse_basic_forms():
    spec = parse_cron("*/15 0-2 1,15 * *")
    assert spec.minutes == (0, 15, 30, 45)
    assert spec.hours == (0, 1, 2)
    assert spec.days == (1, 15)
    assert spec.months == tuple(range(1, 13))
    assert spec.weekdays == tuple(range(7))
    assert not spec.dom_star
    assert spec.dow_star


def test_parse_range_with_step_and_lists():
    assert parse_cron("10-20/5 * * * *").minutes == (10, 15, 20)
    assert parse_cron("1,5,3 * * * *").minutes == (1, 3, 5)
    assert parse_cron("0 0 * * 1-5").weekdays == (1, 2, 3, 4, 5)


def test_day_of_week_zero_and_seven_are_sunday():
    assert parse_cron("0 0 * * 7").weekdays == (0,)
    assert parse_cron("0 0 * * 0").weekdays == (0,)
    assert parse_cron("0 0 * * 5-7").weekdays == (0, 5, 6)
    assert parse_cron("0 0 * * 0,7").weekdays == (0,)


@pytest.mark.parametrize(
    "expr",
    [
        "",
        "* * * *",
        "* * * * * *",
        "60 * * * *",
        "* 24 * * *",
        "* * 0 * *",
        "* * 32 * *",
        "* * * 13 *",
        "* * * * 8",
        "5-1 * * * *",
        "*/0 * * * *",
        "*/x * * * *",
        "5/10 * * * *",
        "1,,2 * * * *",
        ",1 * * * *",
        "-1 * * * *",
        "1- * * * *",
        "MON * * * *",
        "* * * JAN *",
        "@daily",
        "* * * * mon",
        "+5 * * * *",
        "0 0 31 2 *",
        "0 0 30 2 *",
        "0 0 31 4,6 *",
    ],
)
def test_parse_rejects(expr):
    with pytest.raises(CronError):
        parse_cron(expr)


def test_cron_error_is_a_validation_error():
    assert issubclass(CronError, ValidationError)


def test_never_firing_check_allows_leap_day_and_or_form():
    parse_cron("0 0 29 2 *")
    parse_cron("0 0 31 2 1")  # OR form: the Mondays still fire


def test_parse_timezone():
    assert str(parse_timezone(PARIS)) == PARIS
    for bad in ("Mars/Olympus", "", "../etc/passwd", "Europe/", 5):
        with pytest.raises(CronError):
            parse_timezone(bad)


# --- next / latest, plain cases --------------------------------------------------------


def test_nightly_in_paris_summer_and_winter():
    # CEST = UTC+2
    assert next_occurrence("0 3 * * *", PARIS, utc(2026, 10, 18, 0, 0)) == utc(2026, 10, 18, 1, 0)
    # CET = UTC+1 (after the 25 Oct fall-back)
    assert next_occurrence("0 3 * * *", PARIS, utc(2026, 11, 2, 0, 0)) == utc(2026, 11, 2, 2, 0)


def test_next_is_strictly_after_and_ignores_seconds():
    at = utc(2026, 10, 18, 1, 0)
    assert next_occurrence("0 3 * * *", PARIS, at) == utc(2026, 10, 19, 1, 0)
    assert next_occurrence("0 3 * * *", PARIS, at + timedelta(seconds=30)) == utc(
        2026, 10, 19, 1, 0
    )
    assert next_occurrence("0 3 * * *", PARIS, at - timedelta(seconds=1)) == at


def test_latest_is_at_or_before():
    at = utc(2026, 10, 18, 1, 0)
    assert latest_occurrence("0 3 * * *", PARIS, at) == at
    assert latest_occurrence("0 3 * * *", PARIS, at - timedelta(seconds=1)) == utc(
        2026, 10, 17, 1, 0
    )
    assert latest_occurrence("0 3 * * *", PARIS, utc(2026, 10, 18, 5, 17, 33)) == at


def test_results_are_aware_utc_and_accept_any_zone_input():
    after = datetime(2026, 10, 18, 2, 30, tzinfo=timezone(timedelta(hours=2)))  # 00:30Z
    got = next_occurrence("0 3 * * *", PARIS, after)
    assert got.tzinfo is timezone.utc or got.utcoffset() == timedelta(0)
    assert got == utc(2026, 10, 18, 1, 0)
    with pytest.raises(ValueError):
        next_occurrence("0 3 * * *", PARIS, datetime(2026, 10, 18))


def test_every_n_minutes_and_hours():
    assert next_occurrence("*/15 * * * *", "UTC", utc(2026, 1, 1, 10, 14)) == utc(
        2026, 1, 1, 10, 15
    )
    assert next_occurrence("*/15 * * * *", "UTC", utc(2026, 1, 1, 10, 45)) == utc(2026, 1, 1, 11, 0)
    assert latest_occurrence("*/15 * * * *", "UTC", utc(2026, 1, 1, 10, 44)) == utc(
        2026, 1, 1, 10, 30
    )
    assert next_occurrence("0 */6 * * *", "UTC", utc(2026, 1, 1, 18, 0)) == utc(2026, 1, 2, 0, 0)


def test_year_rollover_and_months():
    assert next_occurrence("0 0 1 1 *", "UTC", utc(2026, 6, 1)) == utc(2027, 1, 1)
    assert latest_occurrence("0 0 1 1 *", "UTC", utc(2026, 6, 1)) == utc(2026, 1, 1)
    assert next_occurrence("30 4 * 3,9 *", "UTC", utc(2026, 3, 31, 5, 0)) == utc(2026, 9, 1, 4, 30)
    assert latest_occurrence("30 4 * 3,9 *", "UTC", utc(2026, 8, 31, 5, 0)) == utc(
        2026, 3, 31, 4, 30
    )


# --- day-of-month / day-of-week --------------------------------------------------------


def test_dom_or_dow_when_both_restricted():
    # "1st of the month OR Monday"
    expr = "0 0 1 * 1"
    assert next_occurrence(expr, "UTC", utc(2026, 10, 18)) == utc(2026, 10, 19)  # Monday
    assert next_occurrence(expr, "UTC", utc(2026, 10, 26)) == utc(2026, 11, 1)  # Sunday the 1st
    assert next_occurrence(expr, "UTC", utc(2026, 11, 1)) == utc(2026, 11, 2)  # Monday
    assert latest_occurrence(expr, "UTC", utc(2026, 10, 31)) == utc(2026, 10, 26)


def test_dom_and_dow_when_one_is_a_star_form():
    # Mondays that fall on an odd day of the month ("*/2" starts with '*': AND, not OR)
    expr = "0 0 */2 * 1"
    assert next_occurrence(expr, "UTC", utc(2026, 10, 18)) == utc(2026, 10, 19)  # Mon 19th
    assert next_occurrence(expr, "UTC", utc(2026, 10, 19)) == utc(2026, 11, 9)  # 26th, 2nd even
    assert next_occurrence("0 0 13 * *", "UTC", utc(2026, 10, 14)) == utc(2026, 11, 13)


def test_sunday_as_zero_and_seven():
    # 2026-10-18 is a Sunday
    assert next_occurrence("0 9 * * 0", "UTC", utc(2026, 10, 17)) == utc(2026, 10, 18, 9)
    assert next_occurrence("0 9 * * 7", "UTC", utc(2026, 10, 17)) == utc(2026, 10, 18, 9)
    assert next_occurrence("0 9 * * 6", "UTC", utc(2026, 10, 17)) == utc(2026, 10, 17, 9)


def test_leap_day():
    assert next_occurrence("0 0 29 2 *", PARIS, utc(2026, 1, 1)) == utc(2028, 2, 28, 23, 0)
    # 2100 is not a leap year: 2096 -> 2104 is the longest gap, inside the 8 year horizon
    assert next_occurrence("0 0 29 2 *", "UTC", utc(2096, 3, 1)) == utc(2104, 2, 29)
    assert latest_occurrence("0 0 29 2 *", "UTC", utc(2103, 12, 1)) == utc(2096, 2, 29)
    assert latest_occurrence("0 0 29 2 *", "UTC", utc(2026, 10, 18)) == utc(2024, 2, 29)


# --- DST (Europe/Paris) ----------------------------------------------------------------


def test_spring_forward_gap_skips_that_day():
    # 2026-03-29 02:00 CET -> 03:00 CEST: 02:30 local does not exist that day.
    expr = "30 2 * * *"
    assert next_occurrence(expr, PARIS, utc(2026, 3, 28, 12, 0)) == utc(2026, 3, 30, 0, 30)
    assert next_occurrence(expr, PARIS, utc(2026, 3, 28, 0, 0)) == utc(2026, 3, 28, 1, 30)
    # the day after, 02:30 CEST = 00:30Z
    assert latest_occurrence(expr, PARIS, utc(2026, 3, 29, 23, 0)) == utc(2026, 3, 28, 1, 30)
    assert latest_occurrence(expr, PARIS, utc(2026, 3, 30, 0, 30)) == utc(2026, 3, 30, 0, 30)


def test_spring_forward_other_hours_are_unaffected():
    assert next_occurrence("0 3 * * *", PARIS, utc(2026, 3, 28, 12, 0)) == utc(2026, 3, 29, 1, 0)
    # hourly: 01:00Z..., local 02:00 does not exist, 03:00 CEST = 01:00Z
    assert next_occurrence("0 * * * *", PARIS, utc(2026, 3, 29, 0, 0)) == utc(2026, 3, 29, 1, 0)


def test_fall_back_ambiguous_time_fires_once_at_first_instance():
    # 2026-10-25 03:00 CEST -> 02:00 CET: 02:30 happens twice (00:30Z, then 01:30Z).
    expr = "30 2 * * *"
    first = utc(2026, 10, 25, 0, 30)
    assert next_occurrence(expr, PARIS, utc(2026, 10, 24, 12, 0)) == first
    # the second pass of 02:30 (01:30Z) does not fire; the next run is the next day
    assert next_occurrence(expr, PARIS, first) == utc(2026, 10, 26, 1, 30)


def test_fall_back_latest_during_the_repeated_hour():
    expr = "30 2 * * *"
    first = utc(2026, 10, 25, 0, 30)
    # 01:10Z is 02:10 CET (second pass): the 02:30 CEST occurrence is already behind us
    assert latest_occurrence(expr, PARIS, utc(2026, 10, 25, 1, 10)) == first
    assert latest_occurrence(expr, PARIS, utc(2026, 10, 25, 1, 45)) == first
    assert latest_occurrence(expr, PARIS, utc(2026, 10, 25, 0, 29)) == utc(2026, 10, 24, 0, 30)


def test_fall_back_hourly_has_no_double_fire():
    # 00:00Z = 02:00 CEST fires; 01:00Z = 02:00 CET (repeat) does not; 02:00Z = 03:00 CET does.
    assert next_occurrence("0 * * * *", PARIS, utc(2026, 10, 25, 0, 0)) == utc(2026, 10, 25, 2, 0)


def test_other_zones_work():
    assert next_occurrence("0 3 * * *", "America/New_York", utc(2026, 10, 18, 0, 0)) == utc(
        2026, 10, 18, 7, 0
    )
    assert next_occurrence("0 3 * * *", "Asia/Kolkata", utc(2026, 10, 18, 0, 0)) == utc(
        2026, 10, 18, 21, 30
    )  # 05:30 local already: the next 03:00 is tomorrow's, 21:30Z today


def test_consecutive_occurrences_are_consistent_across_a_year():
    """latest(next(x)) == next(x) and next(latest(x)) > x, over a year of Paris days."""
    t = utc(2026, 1, 1)
    for _ in range(400):
        nxt = next_occurrence("30 2 * * *", PARIS, t)
        assert nxt > t
        assert latest_occurrence("30 2 * * *", PARIS, nxt) == nxt
        assert latest_occurrence("30 2 * * *", PARIS, nxt - timedelta(seconds=1)) < nxt
        t = nxt


# --- misc ------------------------------------------------------------------------------


def test_relative():
    assert relative(timedelta(hours=5, minutes=12)) == "in 5h 12m"
    assert relative(-timedelta(minutes=3)) == "3m ago"
    assert relative(timedelta(days=2, hours=1)) == "in 2d 1h"
    assert relative(timedelta(seconds=10)) == "in <1m"
