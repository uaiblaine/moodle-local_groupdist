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
- The options form's affinity section is now the rule builder from the
  approved mockup: repeatable rows summed with an explicit AND connector,
  list position = priority, reorderable by drag or buttons, mode-coloured
  accents, guardrail-capped (new AMD module `local_groupdist/rules`). The
  member section gains the "Include future-start enrolments" checkbox. The
  preview recap shows one numbered chip per rule; group cards show one badge
  per rule value (keep-apart values highlighted), a "starts <date>" badge on
  future-start members and a per-rule status footer; a new collapsible
  "Rules report" proves each rule globally — value clusters, destination
  groups, split and repeat notes, capped with explicit remainder counts.
  Keep-apart warnings now name the rule's source. Explanatory notes moved
  from the sticky footers into the page content on both the preview and the
  bulk edit screens — the footer carries action buttons only.
- Rule rows scale to large platforms: each row now picks a type first
  (profile field or cohort) and the second control adapts — a field select,
  a cohort select while the platform has at most 10 visible cohorts, or a
  debounced cohort search beyond that (new `local_groupdist_search_cohorts`
  web service applying the same visibility rules as the submit validation).
  Cohorts are never enumerated into the page, and rule validation no longer
  enumerates them either.

### Added

- The audit log now travels in course backups, behind the standard "Include
  course logs" root setting (excluded from anonymised backups and from
  backups without user data, matching core's log handling). On restore the
  runs are recreated in the target course marked "Restored from backup" (a
  badge in the audit UI): the applier and every participant are remapped to
  the restored users, participants missing from the backup become
  pseudonymised rows (userid zeroed, values blanked), and group references —
  both the per-participant planned group and the snapshot's group ids — are
  remapped to the restored groups, keeping the historical id (rendered as a
  since-deleted group) when a group was not carried over.
- Audit log foundation: every apply now records a snapshot — who ran it, the
  ruleset with labels resolved at apply time, each participant's per-rule
  values (the allocator's own input matrix), the planned group and the write
  outcome per member — in the plugin's first two database tables
  (`local_groupdist_run`, `local_groupdist_run_user`). The
  `distribution_applied` event now points at the run row (objectid). The
  privacy provider grew from preference-only to a full metadata + request +
  userlist provider: exports cover applied runs and participations, and
  deletion requests pseudonymise rows (userid zeroed, values blanked) so run
  shapes stay intact. Lifecycle: course deletion purges the course's runs,
  user deletion pseudonymises, and a daily scheduled task enforces the new
  `auditretentiondays` setting (default 365; 0 keeps forever).
- Audit UI: a "Distribution log" course report (new capability
  `local/groupdist:viewauditlog`, default managers and editing teachers,
  listed in the course Reports section) with the paged run list and a run
  detail rendered entirely from the stored snapshot — rules with their
  apply-time labels, warnings, groups (with a marker when a group was since
  deleted) and per-participant outcomes with plain-HTML "Why here?"
  explanations derived from the stored facts: kept-with counts, the
  separated-from peer list with destinations, and conflict lines. Two
  reader-side overlays only: rule values the viewer may not see are masked
  (label shown, values and value-derived facts hidden), and pseudonymised
  rows appear as removed participants.

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
