# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Added

- Initial implementation (Moodle 5.1–5.2): "Distribute participants" bulk
  action on the course group management page, injected client-side and posting
  the selected groups to the plugin's flow.
- Options form mirroring core auto-create groups (role, cohort, only active
  enrolments, allocation orders) plus: ignore users already in the selected
  groups, affinity by profile field (native + custom; keep together or keep
  apart) and per-group seat capacity with optional overbooking.
- Group custom fields "Seats" (number) and "Location" (text), provisioned
  idempotently against core's group custom field handler; admin setting
  `cleanupfieldsonuninstall` controls whether uninstalling removes them.
- Deterministic preview (web service `local_groupdist_get_preview`): totals,
  typed warnings, member samples per group, lazy-loaded five groups at a time
  up to 25; a fingerprint re-checked at apply time aborts on concurrent
  enrolment or membership changes.
- Apply path: inline up to 500 memberships, otherwise a background adhoc task
  with a stored progress bar (core task indicator); memberships written via
  groups_add_member with component stamping; `distribution_applied` event.
  Interrupted background runs resume idempotently: the recompute ignores the
  run's own seed-stamped writes, so the retried task reproduces and completes
  the original plan instead of aborting as stale.

### Changed

### Fixed
