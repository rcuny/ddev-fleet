import pytest

from fleet.core.errors import ValidationError
from fleet.core.naming import allocate_multi_deploy_labels, instance_id, validate_part


@pytest.mark.parametrize(
    "value", ["abc", "abc-123", "a", "a1", "oak", "another-drupal-site", "my-project"]
)
def test_validate_part_accepts_valid_values(value):
    validate_part(value)  # must not raise


@pytest.mark.parametrize(
    "value",
    ["ABC", "Abc", "abc_def", "abc def", "abc--def", "", "abc.def", "-abc", "abc-"],
)
def test_validate_part_rejects_invalid_values(value):
    with pytest.raises(ValidationError):
        validate_part(value)


def test_instance_id_composes_with_double_dash():
    assert instance_id("oak", "develop") == "oak--develop"


def test_instance_id_rejects_invalid_project():
    with pytest.raises(ValidationError):
        instance_id("Bad_Project", "develop")


def test_instance_id_rejects_invalid_instance():
    with pytest.raises(ValidationError):
        instance_id("oak", "Bad Instance")


def test_instance_id_at_exactly_63_chars_is_accepted():
    project = "a" * 30
    instance = "b" * 31
    composed = f"{project}--{instance}"
    assert len(composed) == 63
    assert instance_id(project, instance) == composed


def test_instance_id_at_64_chars_is_rejected():
    project = "a" * 30
    instance = "b" * 32
    assert len(f"{project}--{instance}") == 64
    with pytest.raises(ValidationError):
        instance_id(project, instance)


def test_allocate_multi_deploy_labels_fresh_scan_yields_1_through_n():
    labels = allocate_multi_deploy_labels(set(), "oak", "generic", 20)
    assert labels == [f"generic-{n}" for n in range(1, 21)]


def test_allocate_multi_deploy_labels_skips_past_batch_size_on_second_run():
    existing = {f"oak--generic-{n}" for n in range(1, 21)}
    labels = allocate_multi_deploy_labels(existing, "oak", "generic", 20)
    assert labels == [f"generic-{n}" for n in range(21, 41)]


def test_allocate_multi_deploy_labels_skips_scattered_collisions():
    existing = {"oak--generic-1", "oak--generic-3"}
    labels = allocate_multi_deploy_labels(existing, "oak", "generic", 3)
    assert labels == ["generic-2", "generic-4", "generic-5"]


def test_allocate_multi_deploy_labels_count_zero_returns_empty_list():
    assert allocate_multi_deploy_labels(set(), "oak", "generic", 0) == []


def test_allocate_multi_deploy_labels_raises_before_returning_when_over_63_chars():
    project = "a" * 30
    base_label = "b" * 30  # composed for n=1: 30 + 2 + 30 + 2 = 64 chars
    with pytest.raises(ValidationError):
        allocate_multi_deploy_labels(set(), project, base_label, 1)


def test_allocate_multi_deploy_labels_validates_whole_batch_before_returning():
    # n=1..9 (single-digit suffix) compose to exactly 63 chars (at the
    # limit, allowed); n=10 (double-digit suffix) tips to 64 (over limit).
    # A count=20 batch must raise due to n=10, even though n=1..9 are valid —
    # proving validation happens for the WHOLE batch before any label
    # (or downstream deploy) is dispatched.
    project = "a" * 30
    base_label = "b" * 29
    with pytest.raises(ValidationError):
        allocate_multi_deploy_labels(set(), project, base_label, 20)
