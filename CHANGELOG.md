# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Changed

- The group settings modal on the bulk edit page now carries **every** element
  of core's group edit form. It was missing the enrolment key, the group
  membership visibility menu and its participation checkbox, the current
  picture and the new-picture upload — so any job that touched one of them
  still had to be finished on `group/group.php`. There was no technical
  obstacle: a `filepicker` posts a draft item id in a hidden input rather than
  a file, and `moodleform::save_temp_file()` reads that draft area, so the
  urlencoded dynamic-form payload carries it intact; the YUI picker and the
  `passwordunmask` widget both initialise because the dynamic-form web service
  renders inside `start_collecting_javascript_requirements()` and the client
  replays the collected footer. Verified live in the modal on 5.2.
- Two defects fell out of the same work. The modal never read the stored group
  messaging state, so the menu always showed "No" and saving an untouched
  form silently disabled an enabled group conversation. And it saved the group
  custom fields twice per submit, once itself and once inside
  `groups_update_group()`, which has always done it; the modal's own call is
  gone and the update now passes `$editform` so core writes the picture.
- Core's rule that a group with members cannot change its visibility or
  participation is reproduced, not bypassed. Core enforces it twice — the form
  freezes both elements, `core_group_update_groups` throws — and this modal is
  reached from a bulk flow where most groups already have members, so it
  freezes them exactly as core does and leaves them editable when the group is
  empty.
- The picture upload is validated, which core's group form does not do. Core
  accepts any file, then lets `process_new_icon()` fail inside
  `groups_update_group_icon()`, and that failure path **deletes the group's
  existing picture**. The upload is now checked twice: the picker offers only
  the extensions `process_new_icon()` decodes (GIF, JPEG, PNG), and validation
  reads the file's own image info before accepting it. Both halves are load-
  bearing. The obvious spelling — core's `web_image` file-type group — is
  wrong: it carries `svg`, `svgz` and `webp`, which GD cannot write here, so
  the picker would have advertised three formats that destroy the picture on
  save (`optimised_image` carries `webp` too). And the picker's own check is
  extension-only, so a text file renamed to `.png` still reached the deletion
  path. Both traps are covered by tests that were mutation-checked against the
  naive allowlist, so neither can be quietly simplified back.
- The enrolment key field is capped at 50 characters, the width of the
  `{groups}.enrolmentkey` column, and the length is re-checked server-side.
  Core's form says `maxlength="254"`, and since the modal is a web service
  endpoint the DOM cap is trivially bypassed — an over-long key would reach
  the column as a raw DML failure instead of a field error.
- The bulk edit table's avatar now refreshes when the modal changes the group
  picture. The two states are different elements — an `img` when there is a
  picture, a span holding the initial when there is not — so the row swaps the
  node rather than setting a `src`.

- Bulk edit no longer offers "Cancel". Cells are written through the web
  service as they are saved, so by the time the footer is reached there is
  nothing a cancel could undo — the control now reads "Back to groups", and
  leaving with cells still unsaved asks first rather than discarding them
  silently. The unsaved-changes counter moved out of the sticky footer into
  the toolbar, where page status belongs; the footer carries action buttons
  only. The remaining "Cancel" on the preview page, which really does cancel
  something, and the new "Back to groups" are both `btn-secondary` rather
  than `btn-link`.
- Contrast fixes in the bulk edit table. The over-capacity badge carried
  `bg-light` with no text utility: Bootstrap 5 defaults `.badge` to white, so
  it rendered white on #f8f9fa at 1.05:1 against the 4.5:1 AA floor (15.37:1
  with `text-dark`). The unsaved-changes accent was the hardcoded `#b25e09`,
  which measures 4.67:1 on the light body but 3.30:1 on the 5.2 dark body;
  it now reads `--bs-warning-text-emphasis`, which flips with the theme
  (8.87:1 and 10.94:1).
- New `bootstrap_compat_test`, the observer none of the existing gates can
  be: phpcs reads PHP, the mustache lint reads structure and stylelint reads
  CSS, so a class name that is illegible or deprecated passes every one of
  them. It asserts that every background utility on a badge states its text
  colour, that no Bootstrap 4 spelling survives (they resolve on 5.x only
  through `bs4-compat.scss`, which Moodle 6.0 removes), and that the plugin
  declares no `--mds-*` property in core's design-system namespace. Each
  assertion was mutation-tested against the defect it exists for.

- The distribution audit log scales to runs with hundreds of groups and
  thousands of participants. The run detail no longer loads the whole run:
  group sections are paged (`$OUTPUT->paging_bar`), each section carries one
  window of participants with a link to that group's own page for the rest,
  and two search boxes filter by participant name (SQL, through
  `\core_user\fields::get_sql_fullname()` so the site's own name format is
  what gets matched) and by group name (matched in PHP against the run's
  stored snapshot — `valuesjson` is never searched, its `json_encode`
  escaping makes a portable LIKE impossible to get right). A new AMD module
  (`local_groupdist/audit`) turns the search into a debounced live filter and
  swaps paged sections in place through two new read web services
  (`local_groupdist_get_audit_sections`, `local_groupdist_get_audit_members`),
  keeping one page of the run in the document at a time; every control it
  drives is a plain form, link or paging bar first, so the report still works,
  and stays bookmarkable, with JavaScript off.
- The "why here?" explanations are now derived for the displayed window only,
  which removes a quadratic blow-up: peers of a value were walked in full for
  every member, and a cohort rule gives every participant the same value, so
  one bucket held the whole run (measured: ~106 s of `fullname()` calls alone
  at 3000 participants). Counts and peer lists remain facts about the whole
  run, not about the window — a keep-together line on page two still reports
  every participant sharing the value.
- Participant names in the audit report link to the user profile and open in
  a new tab, in the run list ("Applied by") and on every participant row.
  Pseudonymised rows and users whose account is gone keep the removed marker
  and carry no link.
- Audit report privacy and rendering fixes found while reworking it: the
  "did not fit one group" warning printed the keep-together value even when
  the rule's source was masked for the reader, and now shows the masked
  placeholder whenever any keep-together rule is masked; the "value hidden
  from you" note is emitted for every participant of a masked rule rather
  than only for those holding a value, which by its presence disclosed who
  had one; group names and rule values are formatted with `escape => false`
  so they are not entity-encoded twice on the way to the screen (a group
  called "R&D" read "R&amp;D"), which also keeps a value containing markup
  passable through the web services' `PARAM_TEXT` fields.

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
