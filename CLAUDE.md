# Claude instructions for `local_groupdist`

This file is auto-loaded as context whenever Claude works in this plugin's
directory tree. **Fleet-wide standards live in `~/dev/CLAUDE.md`** (coding
style, CI gates, lang-string rules, the `mdl` environment, git rules) — do not
repeat them here. This file keeps only what is true for this plugin.

Plugin context: a Moodle **local** plugin ("Group distribution") that adds a
"Distribute participants" bulk action to the course group management page,
distributing enrolled users into the SELECTED existing groups with role/cohort
filters, autogroup-style allocation orders, profile-field affinity
(keep-together / keep-apart) and per-group seat capacity + overbooking. It
owns **two database tables** — the audit log (`local_groupdist_run` +
`local_groupdist_run_user`), a per-apply snapshot of who ran it, the rules
with labels resolved at the time and each participant's rule values and
write outcome. Everything else lives in core: "Seats"/"Location" are core
*group custom fields* it provisions, memberships live in `{groups_members}`,
and previews are recomputed deterministically (seed + fingerprint), never
stored. Privacy is a full provider (export + pseudonymising deletes) plus
the bulk-edit column preference. Supports Moodle **5.1 through 5.2**
(`$plugin->requires = 2025100600`, `$plugin->supported = [501, 502]`).
CI is the moodle-an-hochschulen reusable workflow, one job per supported
branch in `.github/workflows/ci.yml` — **update those jobs when `supported`
changes**. Development happens on the m501/m502 stacks; this repo is mounted
via `stacks = auto` at `local/groupdist` (see `~/dev/moodle-dev/plugins.conf`).

## Commands

```sh
mdl ci moodle-local_groupdist            # full CI locally before any push
mdl phpunit m501 local_groupdist         # targeted tests (also run m502)
mdl behat m501 @local_groupdist          # Behat smoke tests
mdl grunt m501 local/groupdist           # rebuild amd/build (commit with src)
mdl purge m501                           # after PHP changes that affect output
```

## Code layout

```
distribute.php               Step 1+2 controller: options form POST target and
                             preview renderer (sticky_footer with apply/back)
apply.php                    Step 3: fingerprint re-check, inline vs adhoc
status.php                   Background apply progress (core task_indicator)
audit.php                    Distribution log course report: run list + run
                             detail from the snapshot, both paged, with the
                             two searches and a pinned-group view (gate:
                             viewauditlog)
bulkedit.php                 Bulk edit table of the selected groups' custom
                             fields (gate: moodle/course:managegroups)
lib.php                      local_groupdist_user_preferences() (column prefs)
classes/
  hook_callbacks.php         before_footer_html_generation → injects the button
  local/options.php          Canonical option value object (form/WS/task shape)
  local/ruleset.php          Ordered affinity ruleset value object (pure; the
                             POST transport is affinityrulesources[]/modes[])
  local/candidates.php       One-query candidate fetch (enrol+role+cohort+affinity)
  local/auditreader.php      Paged/searchable reader over one run's snapshot
                             (windows + the "why here?" peer facts)
  local/allocator.php        Pure deterministic engine (no DB) + typed warnings
  local/allocation.php       Allocator result value object
  local/distribution.php     Builder: groups+fields+counts+allocation+fingerprint
  local/applier.php          Chunked transactional writes via groups_add_member
  local/fields.php           Group custom field provisioning + bulk readers
  local/profilefields.php    Affinity field enumeration (visibility-filtered)
  external/get_preview.php   Paged preview WS (recomputes per call)
  external/get_audit_sections.php One page of a run's group sections (search)
  external/get_audit_members.php One window of one section's participants
  external/audit_ws.php      Shared audit WS preamble + member return shape
  external/search_cohorts.php Cohort search for the rule builder (cohorts are
                             never enumerated; menu <= 10, search beyond)
  external/save_group_fields.php Chunked bulk-edit save (dirty cells only,
                             MAX_CHANGES=200 per call; client chunks at 100)
  output/bulkedit_page.php   Table context builder (also refreshes one row
                             after the settings modal saves)
  form/group_settings_form.php Dynamic-form modal carrying every element of
                             core's group edit form (see the gotcha below)
  task/apply_distribution.php Adhoc apply with stored progress
  event/distribution_applied.php One event per applied run (no objecttable)
amd/src/index_button.js      Injected formaction submit button on group/index
amd/src/preview.js           Preview hydration + lazy load (pages of 5, cap 25)
amd/src/audit.js             Audit report: debounced live search, paging bar
                             intercepted in place, per-section member windows
templates/                   preview shell, stats tiles, group cards, skeleton,
                             sticky footer actions, selected-groups chips
docs/                        Approved HTML mockups + design decisions (export-ignored)
```

## Architecture gotchas

- **Affinity is an ordered ruleset, not a field+mode pair.** Rules are
  `(source, mode)` entries summed with implicit AND; list position = priority:
  together rules cluster by the composite value tuple, apart rules share
  per-group held-value sets keyed `(rule, value)`, group choice minimises the
  lexicographic violation vector in priority order, and together/apart
  contradictions are decided by list position (warning `affinitycontradiction`
  names the winner). `options` exposes `get_affinity_source()`/`_mode()`
  (first rule) only for the single-rule form UI and first-rule display.
  Transport: WS `affinityrules` is a typed multiple structure;
  the POST round trip flattens to parallel `affinityrulesources[]` /
  `affinityrulemodes[]` scalar arrays (nested arrays are not
  `optional_param_array`-able). `ruleset` stays pure — the `maxaffinityrules`
  guardrail is resolved by the caller, and the site-setting lookup is skipped
  for single-rule input so `basic_testcase` suites stay DB-free. Entries
  carrying operator keys are rejected by design (modes never inside a tree).

- **The injected button must stay a `type="submit"` with `formaction` and NO
  `name` attribute.** group/index.php throws `moodle_exception('unknowaction')`
  for any unknown `action` value (index.php:172), and GET navigation overflows
  URL limits with hundreds of `groups[]` ids. The POST carries `groups[]`,
  `id`, `sesskey` natively.
- **Hook guard order matters**: `before_footer_html_generation` runs on every
  page — check `pagetype === 'group-index'` first, then
  `pagelayout === 'standard'` (redirect interstitials keep the pagetype), then
  context/capability. `$PAGE->requires->css()` and `add_body_class()` throw at
  footer time; CSS lives in styles.css.
- **Determinism is the contract**: preview WS calls and the apply recompute
  the same allocation from (options + seed). Anything that would make
  `distribution::build()` non-deterministic (unordered iteration, time(),
  unseeded rand) breaks preview-vs-apply equality. The fingerprint must cover
  EVERY input the allocator's output is a function of: candidate ids, their
  sort keys (name/idnumber — they order the list AND the shuffle's input
  permutation), affinity values, and per-group (id, seats, current count,
  existing-member set). Adding an allocator input without adding it to
  `compute_fingerprint()` reopens the silently-different-plan hole the review
  caught. Apply and the adhoc task refuse on mismatch — the task must NOT
  throw on mismatch (adhoc failures are retried forever).
- **Interrupted applies resume via seed-stamped invisibility**: every
  recompute (counts, existing sets, the ignore-grouped exclusion) skips
  memberships stamped `component='local_groupdist' AND itemid = <this seed>`,
  so a retried adhoc run reproduces the original plan bit-identically, passes
  the fingerprint check and completes idempotently. Any new query feeding
  `distribution::build()` must apply the same exclusion or retries abort as
  "stale" with the remainder unwritten.
- **Candidate query**: `groups_get_potential_members()` is unusable here — it
  dies with `dml_exception('mixedtypesqlparam')` when asked for custom profile
  fields (MDL-70456) and materialises full user records. The own query uses
  `get_enrolled_join()` (which owns the SITEID front-page case — never inline
  the enrolment predicate), role assignments include parent contexts, and
  `onlyactive` is forced ON when the acting user lacks
  `moodle/course:viewsuspendedusers` (autogroup.php:100 parity).
- **Custom fields**: always read via `fields::` bulk helpers (direct
  `{customfield_data}` by fieldid+instanceid); the handler's
  `get_instances_data(..., returnall: false)` is an N+1 trap (one
  `get_record('groups')` per group). Provisioning is lock-serialised
  (customfield tables have no unique indexes) and re-reads through
  `\core_customfield\api` (uncached), not the memoising handler. Seats value
  reads use `get_value()`/raw `decvalue` — `export_value()` returns the
  `displaywhenzero` text for 0. `group_handler::create()` is safe on both
  branches, but its `reset_caches()` moved between 5.1 and 5.2 — don't call it.
- **Applier**: every write goes through `groups_add_member($group, $userobj,
  'local_groupdist', $seed)` — bypassing it skips events/hooks/cache
  invalidations core relies on; passing the user OBJECT (candidates select
  `u.deleted`) skips a per-member `get_record`. A `dml_write_exception` inside
  a transaction poisons it on PostgreSQL: the chunk catch rolls back and
  replays member-by-member WITHOUT a transaction. `groups_add_member` returns
  true for already-members (idempotent re-runs). In the replay, a caught
  `dml_write_exception` is ambiguous (duplicate key vs deadlock/lock timeout —
  same exception type): re-check `groups_is_member()` before counting it as
  added.
- **Affinity source visibility mirrors core** (`profile_field_base::is_visible`,
  listing case): ALL → everyone; TEACHERS → `moodle/site:viewuseridentity` at
  the course; PRIVATE/NONE → `moodle/user:viewalldetails` (NOT
  viewhiddendetails — teachers hold that by default and it would leak hidden
  fields). Cohort rule sources (`cohort_<id>`, binary '1'/empty membership
  column) and raw `cohortid` inputs on the WS/apply are both validated with
  `cohort_get_cohort($id, $context)` so hidden cohorts cannot be used as a
  membership oracle — `profilefields::is_allowed()` owns the dispatch.
  **Never enumerate cohorts** (platforms carry thousands):
  `profilefields::get_fields()` lists fields only; the builder shows a cohort
  menu up to `options_form::COHORT_MENU_LIMIT` and switches to the
  `local_groupdist_search_cohorts` WS beyond it, and per-rule authorization
  is always the O(1) `cohort_get_cohort()` check.
- **"Prevent last small group" is deliberately absent** — core disables it in
  fixed-group-count mode and this allocator balances within one member.
- **WS return structure is an allowlist**: preview data is rendered client-side
  only, from `get_preview` — a field added to the payload must be added to
  `execute_returns()` or `clean_returnvalue` silently strips it.
- **The settings modal is full core parity, and the parts that look
  impossible are not.** Every element of `group/group_form.php` is here —
  including the enrolment key, visibility, participation, and the picture.
  A `filepicker` never posts a file: it posts a **draft item id** in a hidden
  input (`lib/form/filepicker.php`), and `get_new_filename()`/`save_file()`
  both branch on `MoodleQuickForm_filepicker` and read the draft area, never
  `$_FILES` — so the urlencoded dynamic-form payload carries it intact and
  `groups_update_group_icon()` works unchanged. The YUI picker and the
  `passwordunmask` AMD module both initialise because
  `\core_form\external\dynamic_form::execute()` renders inside
  `start_collecting_javascript_requirements()` and the client replays that
  footer through `Fragment.processCollectedJavascript`. `hideIf` rides the
  same mechanism. Three rules follow:
  - `groups_update_group($data, $this)` takes **two** arguments. `$editform`
    is what writes the picture; a third (`$editoroptions`) would re-run the
    `file_postupdate_standard_editor()` this form already did. It also calls
    `instance_form_save()` itself, so the form must not.
  - `definition_after_data()` cannot be copied from core verbatim: core reads
    `getElementValue('id')` and this form's hidden transport is `groupid`, so
    a copy silently finds no group, drops the picture rows and never freezes
    anything.
  - Visibility and participation freeze once the group has members. That is
    core's invariant, enforced twice (the form freezes, and
    `core_group_update_groups` throws `'The visibility of this group cannot be
    changed as it currently has members.'`) — and since this modal is reached
    from a bulk distribution flow, most groups here have members.
- **Two deliberate divergences from core's group form, both safety.** The
  picture upload is validated: core accepts any file and lets
  `process_new_icon()` fail, and that failure path **deletes the group's
  existing picture**. Closing it takes both halves of `PICTURE_TYPES` +
  `validate_picture()`, and the traps are worth remembering — core's
  `web_image` group carries `svg`, `svgz` and `webp` (and `optimised_image`
  carries `webp`), none of which GD writes in `process_new_icon()`, so naming
  a group instead of the extensions advertises three formats that destroy the
  picture; and `MoodleQuickForm_filepicker::validateSubmitValue()` compares
  extensions only, so a renamed file still reaches the deletion path — hence
  the `get_imageinfo()` check. And the enrolment key is capped at 50
  characters, the real `{groups}.enrolmentkey` width, re-checked server-side —
  core's form says `maxlength="254"` and the modal is a web service endpoint,
  so the DOM cap means nothing.
- **The current picture is rendered from a template, not
  `print_group_picture()`.** That helper still wraps the image in a link to
  the participants list for anyone holding `moodle/site:accessallgroups` even
  when passed `$link = false`, and following it from inside the modal
  discards the unsaved form.
- **Bulk edit has no cancel, on purpose.** Saving writes through the web
  service as it goes, so a cancel in the footer would undo nothing — the
  control is "Back to groups", and `bulkedit.js` confirms before leaving
  while cells are still dirty. The sticky footer carries action buttons
  only; the unsaved-changes counter is page status and lives in the toolbar.
- **`bootstrap_compat_test` is the only gate that reads a class name.**
  Badge backgrounds must state a text utility (BS5 defaults `.badge` to
  white, so `bg-light` alone renders ~1.05:1), no Bootstrap 4 spelling may
  survive, and `--mds-*` is core's namespace. Colours come from theme tokens
  with a fallback chain, never a literal: 5.1 and 5.2 ship dark mode, and a
  hardcoded accent that passes on white can fail on `--bs-body-bg` #1d2125.
- **Bulk edit saves are payload-bounded by design**: only dirty cells travel,
  the client slices sequential chunks of 100 and `save_group_fields` rejects
  calls above `MAX_CHANGES` (200). Partial cell saves are safe because
  customfield data controllers early-return on absent `customfield_<shortname>`
  properties (`data_controller::instance_form_save`, property_exists check) —
  never "helpfully" fill in the other fields' properties, that would wipe them.
- **The audit report reads the snapshot through windows, and every number in
  it is still a fact about the whole run.** `auditreader` pages group sections
  and participants; the "why here?" lines are built for the displayed window
  only, but the counts and peer lists behind them come from an exhaustive
  pass, never from the window (a page-local count would read as correct and
  be wrong). The peer pass keeps at most `PEER_CAP + 1` rows per
  (rule, value, group) bucket — that cap is what removed the quadratic walk
  the unpaged reader had, and `+ 1` is load-bearing: a member's own row sits
  in its own bucket and is removed before the list is sliced, which is also
  the only thing keeping a participant out of their own "separated from"
  line in the no-group bucket. The scan runs the whole run only when a
  keep-apart rule is in play, in keyset chunks (neither DB driver streams a
  plain recordset). Searching participants is SQL over `{user}`; searching
  groups is PHP over the snapshot — `valuesjson` is never searched, because
  `json_encode` escaping (`/` → `\/`, non-ASCII → `\uXXXX`) makes a portable
  LIKE silently wrong.
- **Anything the audit report displays is `format_string(..., escape => false)`
  first.** Values land in a Mustache double stash and in `PARAM_TEXT` web
  service fields: the default escaping would be encoded twice on screen, and
  an unstripped `<` makes `clean_returnvalue()` throw, so the page renders
  and then dies on the first search keystroke.
- **The audit log is a snapshot, never a reference**: `runlog` stores rule
  labels, per-user values and group names as they were at apply time; the
  audit UI derives explanations from these stored facts, never by replaying
  the engine. Deletion pseudonymises (userid 0, values blanked) instead of
  removing rows; course deletion purges via observer (the recycle bin keeps
  a backup file, not the course). Retention = `auditretentiondays` setting +
  daily `cleanup_audit` task. `applier::apply()` requires the runid — the
  `distribution_applied` event carries it as objectid.
- **The privacy provider is a full provider**: the audit tables (metadata +
  export + pseudonymising deletes + userlist) plus the collapsible-columns
  preference `local_groupdist_bulkedit_hiddencols` (declared in
  `lib.php:local_groupdist_user_preferences()` so the WS may set it). A new
  user preference means extending BOTH lib.php and the privacy provider.

## Testing notes

- `allocator_test` is pure `basic_testcase` — keep it DB-free.
- Provisioning tests must call `fields::reset_field_cache()` around
  `ensure_fields_exist()` (request-level static cache).
- Saving group field values in tests goes through
  `group_handler::create()->instance_form_save()` **as admin** — the handler
  silently drops fields the current user cannot edit.
- WS tests need `$_POST['sesskey'] = sesskey();` before
  `call_external_function()`. The same applies to building a `dynamic_form`
  with submitted `$ajaxformdata`: `_process_submission()` calls
  `confirm_sesskey()`, which reads the superglobal and never the payload.
- Submitting the settings modal in a test means submitting what the browser
  would. `customfield_number` pairs its input with a hidden
  `<name>_maximum` ceiling element and a `compare`/`lt` rule, so omitting it
  fails validation with a maximum-value error; and
  `customfield_text::instance_form_validation()` indexes `$data[$elementname]`
  unguarded, so omitting a text field raises a warning the `--fail-on-warning`
  gate turns into a failure. The enrolment key policy is **on by default**
  (`groupenrolmentkeypolicy`), so a test key must either be strong or the
  policy explicitly switched off.
- The `@covers`-in-docblock PHPUnit deprecations match core 5.1/5.2 style;
  they do not fail CI.

## When in doubt

Follow the patterns in existing files. The codebase is internally
consistent — if a new file feels like it matches no existing shape,
re-examine the approach.
