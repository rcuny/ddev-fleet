import pytest

from fleet.core.errors import ValidationError
from fleet.core.naming import instance_id, validate_part


@pytest.mark.parametrize("value", ["abc", "abc-123", "a", "oak", "another-drupal-site"])
def test_validate_part_accepts_valid_values(value):
    validate_part(value)  # must not raise


@pytest.mark.parametrize(
    "value",
    ["ABC", "Abc", "abc_def", "abc def", "abc--def", "", "abc.def"],
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
