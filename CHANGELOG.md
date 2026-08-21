# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Changed

- The distribution log lays its group sections out **three to a row** instead
  of one full-width section each. Percentage flex-basis rather than a grid:
  that is what caps the column count, and it is governed by the container, so
  the block drawer narrowing `#region-main` is handled where a viewport media
  query would fire at the wrong moment. Measured on the real report — three
  columns above about 930px, two down to about 615px, one below (three 19rem
  columns plus two 0.5rem gaps is 928px; two is 616px). The 19rem floor is
  measured too: at 17rem three columns still fit an 860px region and wrapped
  participant names.
- A section card opens with **five participants** rather than twenty, since a
  card is now a third of the page wide. The first "show more" still pulls a
  full twenty, so a fifty-member group is three clicks rather than nine — the
  preview size and the window size are separate constants now
  (`MEMBERS_PREVIEW` and `MEMBERS_PER_PAGE`).
- The "why here?" disclosure is indented under the participant it belongs to,
  and its summary carries **core's own chevron** (`t/collapsedchevron`) rotated
  on open, instead of the browser's default triangle — which did not read as a
  control. Core ships no `<details>` styling at all, so there was nothing to
  inherit.
- The outcome badge now appears only when the outcome is **not** the expected
  one. On a run that worked every row said "written", and a column of identical
  green badges told the reader nothing; `failed`, `no seat`, `no write needed`
  and `planned` still show. The label stays in the web service payload for
  anyone who needs it — the templates simply do not paint it.

### Fixed

- The distribution log no longer reports a live group as deleted. The
  "group since deleted" marker asked `groups_get_all_groups()` whether the
  group was still there, and that helper is visibility-filtered the same way
  `groups_is_member()` is: it uses its cache only when
  `can_view_all_groups()` is true, and otherwise returns a group only when its
  visibility is ALL, or the reader is a member and it is MEMBERS or OWN. A
  group set to "Not visible" is absent from it for **every** reader without
  `moodle/course:viewhiddengroups`, member or not, so the report asserted that
  a run had written into a group that no longer existed while the group and
  its memberships were intact — and asserted it per reader, the same run
  reading differently for a manager and for a teacher on a restricted role.
  Whether a group still exists is a fact about the course, not a display
  decision. It is now read straight from the table, which is also one lighter
  query than loading every group record. Filtering bought no privacy here: the
  group's name and its members are rendered from the run snapshot beside the
  badge either way, so nothing that was hidden becomes visible. Found by
  sweeping for the defect class behind the applier fix below, rather than
  reported.
- A membership that already existed could be logged as a failure. The
  non-transactional replay in `applier::apply()` — the path a caught
  `dml_write_exception` falls into, where a duplicate key and a genuine write
  failure (deadlock victim, lock timeout) are indistinguishable by type —
  asked `groups_is_member()` whether the row had landed. That helper is
  visibility-filtered: it short-circuits to a plain `record_exists()` only
  when `\core_group\visibility::can_view_all_groups()` is true, and otherwise
  admits another participant's row only for a group whose visibility is ALL,
  or MEMBERS with the viewer a member too. On a group set to "Only visible to
  members" or "Not visible" it answered "not a member" for a row that plainly
  existed, so a successful write was scored as failed — in the run summary and
  in the audit log. In a feature whose product is an auditable record, the
  record was the thing that was wrong. The probe now reads `{groups_members}`
  directly: this is a write-side integrity check, not a display decision, and
  visibility filtering is the wrong question to ask of it. Display-side uses
  of the helper are untouched — there the filtering is correct, and removing
  it would leak membership of hidden groups.
- The reachability is narrow, and worth stating plainly rather than
  overselling. On a stock site the distributor holds
  `moodle/course:viewhiddengroups` — `local/groupdist:distribute` defaults to
  editingteacher and manager, and viewhiddengroups defaults to teacher,
  editingteacher and manager — so `can_view_all_groups()` is true and the
  plain path is taken. Reaching the filtered branch needs a custom role or an
  explicit prevent, PLUS a target group whose visibility is not ALL, PLUS
  actually entering the replay. What makes it worth fixing rather than noting
  is that the last condition is not independent of the others: core's own
  `groups_add_member()` guards its insert with the same `groups_is_member()`,
  so the same blindness fails to see the existing row, inserts, and raises the
  duplicate key that enters the replay. In that configuration every
  pre-existing membership is mis-scored — not an occasional race, and exactly
  what an interrupted adhoc apply re-attempts on resume.
- Cohort names and rule-source labels are no longer escaped twice, finishing
  the sweep. The most visible instance was the affinity rule builder, the
  first screen of the flow: `rules.js` writes search results with
  `option.textContent`, which does not interpret entities, so a cohort named
  "Ciencias & Letras" was shown to the teacher literally as
  "Ciencias &amp; Letras". The same labels reach the preview payload, the
  recap chips and the audit snapshot, all through Mustache double stashes.
- **Correcting a regression from the previous release entry.** Flipping
  `fields::get_seats_label()` put a raw ampersand into the two places that
  render it unescaped: the "use seats" checkbox label and the no-seats note,
  which core prints through `{{{label}}}` and `{{{element.html}}}`. Measured
  in the rendered form. The label now takes an `$escape` switch — the shape
  core itself uses in `field_controller::get_formatted_name()`, and for the
  same reason — and the options form asks for the escaped spelling. Both
  directions are pinned by tests, because the two spellings are one line
  apart and each looks wrong from the other's point of view.
- The cohort menu on the options form keeps its escaped label on purpose and
  now has a test saying so. It is a real `select`, and core renders a
  select's options through a triple stash, so it needs the opposite treatment
  from the rule-builder list a few lines below it in the same file.
- Existing audit rows keep the escaped spelling of a rule label, and that is
  deliberate. The run log is a snapshot of what things were called at apply
  time, never replayed and never rewritten, and no migration could tell an
  escaped "A & B" from a field genuinely named "A &amp; B" — the escaping is
  not injective. Only labels containing an ampersand are affected, and only
  cosmetically.
- One participant could break the whole distribution preview. Affinity values
  reached the payload straight from `{user_info_data}` with no `format_string()`
  anywhere on the path, into return fields declared `PARAM_TEXT`, whose cleaner
  runs `strip_tags()`; `validate_param()` then throws because the cleaned string
  differs from the original, and the exception surfaces through
  `Notification.exception` — so every page of the preview failed for everyone,
  not just the row at fault. Reproduced before fixing, and the reproduction is
  now three tests.
- The vector is narrower than "any profile field" and worth naming, because it
  is the one that will come back: a **textarea** custom profile field. The
  standard fields self-sanitise — `user_update_user()` puts city, department
  and institution through `core_user::clean_field()` with `PARAM_TEXT` — but
  `profile_field_textarea` declares `PARAM_RAW`, with its own comment reading
  "We MUST clean this before display!", and `profilefields::get_fields()`
  offers every custom field whatever its datatype. So a value holding `<3`,
  `<TI>` or real markup was reachable through ordinary use.
- The values now go through one `display_value()` helper that resolves the
  mapped sources (country, cohort) and otherwise formats the stored text with
  `escape => false` — stripping the markup, which is what makes it passable
  through `PARAM_TEXT`, without reintroducing the double-escaping fixed above.
  `format_string()` strips tags in both escape modes, so the two fixes do not
  fight. This is the treatment `auditreader::display_value()` has always
  applied, which is exactly why the audit report was never affected.
- The group location travels through the same `PARAM_TEXT` field and is now
  formatted with it. That one is defence in depth rather than a fixed crash:
  `customfield_data` declares `charvalue` as `PARAM_TEXT`, so the persistent
  refuses to store a location containing a bare `<` in the first place.
- The seats and location field labels are no longer escaped twice either,
  finishing the sweep the bulk edit row fix started. Measured on 5.2 with a
  field named "Vagas & Lugares": the page carried `Vagas &amp;amp; Lugares`
  in the mass-apply label, the empty-seats filter, the column menu and both
  legend entries, while the column header beside them — fixed in the previous
  change — already carried `Vagas &amp; Lugares`. The same two spellings on
  one screen. The label reaches those five lines as a `{{#str}}` parameter,
  which the string helper renders through a double stash of its own before
  substituting it, and the lambda's return is then inserted unescaped; only a
  real render exercises that, so there is now a test that renders the whole
  page and asserts no name reaches it escaped twice. The selected-groups chips
  on the distribution page had the same defect and are fixed with them.
- The labels are formatted in the system context now, not whatever context the
  page happened to be in. Group custom fields are defined site-wide
  (`group_handler::get_configuration_context()`), so a course-level filter
  override has no business rewriting a global field's name.
- The location label crosses the preview web service unescaped, which is safe
  and was worth confirming rather than assuming: it is declared `PARAM_TEXT`,
  and `clean_param_value_text()` only handles tags and multilang markup — it
  never decodes, re-escapes or doubles an entity. Measured both spellings
  through `clean_returnvalue()`; both pass through byte-identical.
- The bulk edit table's ID number cell now refreshes when the settings modal
  changes it, instead of showing the old value until the page is reloaded.
  The badge exists only when the group has an ID number, so all three
  transitions — changed, cleared, newly set — go through one path that
  replaces the node and re-initialises its tooltip; Bootstrap moves a
  tooltip's title into its own state at init, so updating the attribute in
  place would have left the old text in the tooltip.
- Group names and custom field labels are no longer escaped twice in the bulk
  edit table. `bulkedit_page` formatted them with `format_string()`'s default
  escaping and then handed them to Mustache double stashes, so a group called
  "Ana & Bruno" read "Ana &amp;amp; Bruno" — and read correctly a moment later,
  because `bulkedit.js` writes the refreshed row with `textContent` after the
  settings modal saves. The same group, two spellings, depending on whether
  the page had been reloaded. They are formatted `escape => false` now, which
  is the rule the audit report already follows.

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
