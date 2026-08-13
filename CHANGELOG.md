# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Changed

- Affinity now travels as an ordered ruleset (`\local_groupdist\local\ruleset`,
  new value object) instead of the `affinityfield`/`affinitymode` scalar pair,
  across the options object, the preview web service (typed `affinityrules`
  parameter), the preview/apply POST round trip (parallel
  `affinityrulesources[]`/`affinityrulemodes[]` hidden inputs) and the adhoc
  task customdata. No backward compatibility is kept — the plugin has no
  installed base. Rules are validated structurally (source pattern, mode enum,
  no duplicate sources, entries carrying operator keys rejected) with a
  configurable count guardrail (`maxaffinityrules` setting, default 10).
  The options shape also gains `includefuture` (future-start enrolments).
- The allocator is now genuinely multi-rule: keep-together rules AND-combine
  into composite tuple clusters, keep-apart rules are enforced simultaneously
  through per-group held-value sets keyed by (rule, value), groups are chosen
  by a lexicographic violation vector ordered by rule priority, and
  together/apart contradictions are resolved by list position (the winner is
  reported via the new `affinitycontradiction` warning; `novalue` and
  `apartinfeasible` warnings became per-rule). Single-rule behaviour is
  unchanged. Candidates carry one value column per rule (custom profile
  fields are bulk-fetched per rule — no join fan-out) and the fingerprint
  covers every rule's per-user values. The "include future-start enrolments"
  option is now effective: an own active-or-future enrolment predicate
  (agreeing with `get_enrolled_join()`, SITEID exempt) gated on
  `moodle/course:viewsuspendedusers`.
- Cohorts are now affinity rule sources (`cohort_<id>`): a binary membership
  column bulk-fetched per rule — keep-apart separates cohort mates pairwise
  (clique semantics without materialised edges), keep-together clusters them.
  Visible cohorts appear in the source picker ("Cohort: ..." entries); every
  entry point validates cohort sources with `cohort_get_cohort()`, so a
  hidden cohort id cannot become a membership oracle. Cohort membership churn
  between preview and apply shifts the fingerprint like any other rule value.

### Added

- Bulk edit groups: a second action on the group management page opening a
  table of the selected groups — picture, name, ids, member count and every
  group custom field — with inline editing (number/text/select/checkbox),
  mass-apply for the seats field, an empty-seats filter, per-user collapsible
  columns (user preference), floating tooltips on truncated names/idnumbers,
  a dynamic soft-red overbooking indicator on the members column, a
  responsive card layout on narrow screens, and a per-row modal wrapping the
  core group settings (dynamic form). Saves go through the chunked
  `local_groupdist_save_group_fields` web service: only changed cells are
  sent, at most 200 per call, sequential chunks of 100 client-side.
- The distribution UI now echoes the STORED names of the provisioned custom
  fields (set in the provisioning admin's language) instead of translated
  placeholders, so a site provisioned in English shows "Seats" even in a
  Portuguese UI.

### Fixed

- Switching the interface language on the distribution pages no longer
  throws "A required parameter (sesskey) was missing": rendering requires no
  sesskey (form submissions and the apply/save endpoints keep their own
  checks) and a bare GET quietly returns to the groups page.

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
