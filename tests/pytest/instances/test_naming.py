import hashlib

import pytest

from fleet.core.errors import ValidationError
from fleet.core.naming import (
    allocate_free_label,
    allocate_multi_deploy_labels,
    derive_label,
    instance_id,
    validate_part,
)


@pytest.mark.parametrize(
    "value", ["abc", "abc-123", "a", "a1", "oak", "another-drupal-site", "my-project"]
)
def test_validate_part_accepts_valid_values(value):
    validate_part(value)  # must not raise


@pytest.mark.parametrize(
    "value",
    ["ABC", "Abc", "abc_def", "abc def", "abc--def", "", "abc.def", "-abc", "abc-", "demo\n"],
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


def test_instance_id_at_exactly_59_chars_is_accepted():
    project = "a" * 30
    instance = "b" * 27
    composed = f"{project}--{instance}"
    assert len(composed) == 59
    assert instance_id(project, instance) == composed


def test_instance_id_at_60_chars_is_rejected():
    project = "a" * 30
    instance = "b" * 28
    assert len(f"{project}--{instance}") == 60
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


def test_allocate_multi_deploy_labels_cuts_the_base_to_fit_the_suffix():
    project = "a" * 30
    base_label = "b" * 30  # uncut: 30 + 2 + 30 + 2 = 64 chars
    labels = allocate_multi_deploy_labels(set(), project, base_label, 1)
    assert len(instance_id(project, labels[0])) <= 59


def test_allocate_free_label_returns_bare_base_when_free():
    assert allocate_free_label(set(), "oak", "generic") == "generic"


def test_allocate_free_label_suffixes_1_when_base_taken():
    existing = {"oak--generic"}
    assert allocate_free_label(existing, "oak", "generic") == "generic-1"


def test_allocate_free_label_suffixes_2_when_base_and_1_taken():
    existing = {"oak--generic", "oak--generic-1"}
    assert allocate_free_label(existing, "oak", "generic") == "generic-2"


def test_allocate_free_label_reuses_gap_when_base_and_2_taken_but_not_1():
    existing = {"oak--generic", "oak--generic-2"}
    assert allocate_free_label(existing, "oak", "generic") == "generic-1"


def test_allocate_free_label_fits_suffixed_result_in_limit():
    # FLE-166: the bare base fits (30 + 2 + 27 = 59) but base + "-1" would not;
    # the base is cut (hash-suffixed) so the suffixed id stays within 59.
    project = "a" * 30
    base_label = "b" * 27
    existing = {f"{project}--{base_label}"}
    label = allocate_free_label(existing, project, base_label)
    assert label.endswith("-1")
    assert len(instance_id(project, label)) <= 59


def test_allocate_multi_deploy_labels_validates_whole_batch_before_returning():
    # A batch past -99 (3-digit suffix) overflows the "-NN" reserve and must
    # raise for the WHOLE batch before any label is dispatched.
    project = "a" * 30
    base_label = "b" * 29
    with pytest.raises(ValidationError):
        allocate_multi_deploy_labels(set(), project, base_label, 100)


# --- derive_label (repo:push deploy rules) ---------------------------------


def _sha6(branch):
    return hashlib.sha1(branch.encode("utf-8")).hexdigest()[:6]


def test_derive_label_prefix_and_strip_suffix():
    label = derive_label(
        "website",
        "feature/ABC-1898-playwright-ci-fleet--pw-run",
        label_prefix="pw-",
        strip_suffix="--pw-run",
    )
    assert label == "pw-feature-abc-1898-playwright-ci-fleet"
    assert instance_id("website", label) == "website--pw-feature-abc-1898-playwright-ci-fleet"


def test_derive_label_strips_only_a_trailing_suffix():
    label = derive_label(
        "website", "a--pw-run-b--pw-run", label_prefix="pw-", strip_suffix="--pw-run"
    )
    assert label == "pw-a-pw-run-b"


def test_derive_label_fixed_label_ignores_the_branch():
    assert derive_label("website", "develop", label="pw-regression") == "pw-regression"
    assert derive_label("website", "release/1.2", label="PW Regression") == "pw-regression"


def test_derive_label_without_prefix_or_suffix_slugifies_the_branch():
    assert derive_label("website", "Feature/ABC_12") == "feature-abc-12"


def test_derive_label_suffix_without_prefix():
    assert derive_label("website", "feat/x--bare", strip_suffix="--bare") == "feat-x"


def test_derive_label_exactly_59_chars_is_not_shortened():
    # "website--" is 9 chars, so a 50-char label makes a 59-char id.
    branch = "a" * 47 + "--pw-run"
    label = derive_label("website", branch, label_prefix="pw-", strip_suffix="--pw-run")
    assert label == "pw-" + "a" * 47
    assert len(instance_id("website", label)) == 59


def test_derive_label_overflow_is_cut_and_hashed_to_fit():
    branch = "a" * 48 + "--pw-run"  # one char over: 51-char label
    label = derive_label("website", branch, label_prefix="pw-", strip_suffix="--pw-run")
    assert label == ("pw-" + "a" * 48)[:43] + "-" + _sha6(branch)
    assert len(instance_id("website", label)) == 59


def test_derive_label_hash_is_over_the_whole_pushed_branch():
    long_a = "x" * 80 + "-a--pw-run"
    long_b = "x" * 80 + "-b--pw-run"
    kw = dict(label_prefix="pw-", strip_suffix="--pw-run")
    a, b = derive_label("website", long_a, **kw), derive_label("website", long_b, **kw)
    assert a != b
    assert a.endswith("-" + _sha6(long_a)) and b.endswith("-" + _sha6(long_b))


def test_derive_label_is_deterministic():
    branch = "feature/" + "long-name-" * 10 + "--pw-run"
    kw = dict(label_prefix="pw-", strip_suffix="--pw-run")
    assert derive_label("website", branch, **kw) == derive_label("website", branch, **kw)


def test_derive_label_cut_on_a_dash_does_not_leave_a_double_dash():
    # The 43-char cut lands right after the dash following the a's.
    branch = "a" * 39 + "-" + "b" * 20 + "--pw-run"
    label = derive_label("website", branch, label_prefix="pw-", strip_suffix="--pw-run")
    assert label == "pw-" + "a" * 39 + "-" + _sha6(branch)
    assert "--" not in label
    instance_id("website", label)  # must not raise


def test_derive_label_reserve_leaves_room_for_an_alias_prefix():
    # reserve=6 (`odihr-`): "website--" + label must be <= 59 - 6 = 53, so the
    # label may be 44 chars. Exactly 44 is not shortened ...
    branch = "a" * 41 + "--pw-run"
    kw = dict(label_prefix="pw-", strip_suffix="--pw-run", reserve=6)
    label = derive_label("website", branch, **kw)
    assert label == "pw-" + "a" * 41
    assert len(f"odihr-{instance_id('website', label)}") == 59


def test_derive_label_reserve_overflow_is_cut_and_hashed_to_fit():
    branch = "a" * 42 + "--pw-run"  # one char over the reserved room
    kw = dict(label_prefix="pw-", strip_suffix="--pw-run", reserve=6)
    label = derive_label("website", branch, **kw)
    assert label == ("pw-" + "a" * 42)[:37] + "-" + _sha6(branch)
    assert len(f"odihr-{instance_id('website', label)}") == 59
    assert derive_label("website", branch, **kw) == label  # deterministic


def test_derive_label_reserve_zero_is_the_default():
    branch = "a" * 52 + "--pw-run"
    kw = dict(label_prefix="pw-", strip_suffix="--pw-run")
    assert derive_label("website", branch, reserve=0, **kw) == derive_label("website", branch, **kw)


def test_derive_label_reserve_applies_to_a_fixed_label_too():
    label = derive_label("website", "develop", label="a" * 60, reserve=6)
    assert len(f"odihr-website--{label}") == 59


def test_derive_label_reserve_can_make_the_project_too_long():
    with pytest.raises(ValidationError, match="too long"):
        derive_label("p" * 46, "x" * 80, label_prefix="pw-", reserve=10)


def test_derive_label_empty_slug_raises():
    with pytest.raises(ValidationError, match="cannot slugify"):
        derive_label("website", "--bare", strip_suffix="--bare")


def test_derive_label_overflow_with_a_huge_project_name_raises():
    with pytest.raises(ValidationError, match="too long"):
        derive_label("p" * 60, "feature/a-long-branch-name", label_prefix="pw-")


def test_derive_label_short_label_with_a_long_project_still_works():
    label = derive_label("p" * 52, "x", label_prefix="a-")
    assert label == "a-x"
    assert len(instance_id("p" * 52, label)) == 57


def test_derive_label_user_prefixed_and_uppercase_branch():
    label = derive_label(
        "website",
        "jdoe/hotfix/ABC-1896-Font-patch--pw-run",
        label_prefix="pw-",
        strip_suffix="--pw-run",
    )
    assert label == "pw-jdoe-hotfix-abc-1896-font-patch"
    assert instance_id("website", label) == "website--pw-jdoe-hotfix-abc-1896-font-patch"


# --- FLE-166: 59-char cap (DDEV appends "-web" to the 63-char hostname) -------


def test_instance_id_cap_is_59_so_web_hostname_fits_63():
    project = "ddev-fleet-ci"
    ok = "x" * (59 - len(project) - 2)
    assert len(instance_id(project, ok)) == 59
    with pytest.raises(ValidationError):
        instance_id(project, ok + "x")


def test_63_char_base_plus_collision_suffix_stays_within_59():
    project = "ddev-fleet-ci"
    branch = "ci-feature/fle-153-rollback-upgrade-test-for-the-legacy-line-xxxxxxxx"
    base = derive_label(project, branch, reserve=len("-NN"))
    assert len(f"{project}--{base}") <= 56
    taken = {f"{project}--{base}"}
    for expected in (1, 2):
        lbl = allocate_free_label(taken, project, base)
        assert lbl == f"{base}-{expected}"
        full = f"{project}--{lbl}"
        assert len(full) <= 59 and len(f"{full}-web") <= 63
        taken.add(full)


def test_63_char_base_label_with_multi_deploy_suffix_stays_within_59():
    project = "ddev-fleet-ci"
    base = "z" * (63 - len(project) - 2)  # a 63-char id, the pre-fix maximum
    for lbl in allocate_multi_deploy_labels(set(), project, base, 12):
        assert len(f"{project}--{lbl}-web") <= 63


def test_short_ids_are_not_renamed():
    assert derive_label("oak", "feature/abc") == "feature-abc"
    assert allocate_free_label({"oak--abc"}, "oak", "abc") == "abc-1"
    assert allocate_multi_deploy_labels(set(), "oak", "abc", 2) == ["abc-1", "abc-2"]
