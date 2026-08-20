# local_groupdist — design documentation

## Approved UI mockups

The HTML files under [`mockups/`](mockups/) are the self-contained,
navigable prototypes the flow was designed and approved against.
Open them in any browser; they carry no external dependencies
and follow Moodle Boost's visual language. Every label is in English and
mirrors the shipped `lang/en` string, so a mockup and the screen it stands for
read the same; the plugin itself resolves all labels through its language
packs (`en` + `pt_br`). Sample people and cities are Brazilian, which is the
deployment these screens were designed for.

| File | Screen |
|------|--------|
| [`mockups/step1-options.html`](mockups/step1-options.html) | Step 1 — distribution options form (moodleform sections, seats controls with enable/disable behaviour; the affinity section is the ordered rule list, with the interactive builder prototyped in `affinity-rules.html`; approved 2026-08-11, refreshed 2026-08-20) |
| [`mockups/step2-preview.html`](mockups/step2-preview.html) | Step 2 — preview (recap with one numbered chip per rule, stat tiles, warnings, group cards with capacity meters, per-rule status footer, future-start badges, simulated lazy loading in pages of 5 up to the 25-group cap; approved 2026-08-11, refreshed 2026-08-20) |
| [`mockups/distribution-log.html`](mockups/distribution-log.html) | Distribution log — the audit report as built (2026-08-20). Both screens: the run list under Course › Reports, and one run's detail **paged on two axes** — group sections, and participants inside each section — with the two searches filtering live, profile links opening in a new tab, the masked-rule note, pseudonymised participants, the deleted-group marker and the no-JS route into a single group's own page |
| [`mockups/bulk-edit.html`](mockups/bulk-edit.html) | Bulk edit groups (approved v3, 2026-08-12; refreshed 2026-08-20) — table of selected groups with inline custom-field editing, mass-apply for seats, dynamic overbooking indicator on the members column, empty-seats highlighting + filter, collapsible columns menu, floating tooltips on truncated names/idnumbers, per-row "Edit" modal wrapping core's group settings, responsive card layout on mobile. The footer carries "Save changes" and "Back to groups" only — there is no cancel, because saving writes as it goes, and leaving with unsaved cells is confirmed first |
| [`mockups/affinity-rules.html`](mockups/affinity-rules.html) | Multi-rule affinity (approved 2026-08-13) — rule builder (repeatable rows summed with an explicit "AND" connector, list position = priority, drag + button reordering), member enrolment options (new "include future-start enrolments" checkbox, distinct from suspended), preview explainability alternatives A (per-rule badges on group cards), B (per-participant "why here?") and C (per-rule report with clusters/pairs), and the audit log screens (run list under the course Reports section, run detail reading the stored snapshot, reader-side masking, pseudonymised participants, deleted-group marker). Rule rows pick a **type** first (profile field / cohort); the cohort picker is a menu up to 10 visible cohorts and a debounced search beyond that — the mockup has a toggle simulating the large-platform search mode. Section 3 explored the audit log; the built report now has its own prototype in `distribution-log.html` |

The mockups link to each other the same way the real pages flow ("Preview
distribution" / "Back and adjust"). The bottom action bar represents core's
`\core\output\sticky_footer`, which the implemented pages use — and it carries
**action buttons only**: explanatory notes and status counters live in the page
content, which is why the bulk edit footer has no cancel and no counter.

## Design decisions worth knowing

- **Deterministic recompute, no plan storage.** Every preview page call and
  the apply step recompute the full allocation from (options + seed). A
  sha256 fingerprint over every input the plan is a function of — candidate
  ids, their sort keys, each rule's per-user values and each group's
  (id, seats, current members) — is returned by the preview and re-verified at
  apply: any concurrent enrolment/membership change aborts the apply instead
  of silently writing a plan the teacher never saw. **Previews** are still
  never stored; what is stored is the audit snapshot of an *applied* run,
  which is why the plugin owns two tables and ships a full privacy provider
  (export, userlist and pseudonymising deletes) rather than a `null_provider`.
- **"Seats"/"Location" are core group custom fields**, provisioned
  idempotently under a lock into a plugin-managed category. Uninstall
  cleanup is an admin opt-in (`cleanupfieldsonuninstall`), patterned on
  availability_competency's `cleanuponcompetencydeletion`.
- **Entry point is a JS-injected submit button with `formaction`** on
  /group/index.php — the page has no server-side extension point, a button
  with `name="action"` would hit core's unknown-action exception, and GET
  navigation would overflow URL limits with hundreds of selected groups.
- **"Prevent last small group" was deliberately dropped**: core disables it
  in the fixed-group-count mode (the only mode here), and this allocator
  always balances group sizes within one member.
- **Affinity constraints apply to the users being distributed in the run**;
  values of existing group members are not considered (documented in the
  help strings).

## Multi-rule affinity decisions (approved 2026-08-13)

- **Flat ordered rule list, AND only.** Affinity becomes an ordered list of
  rules `(source, mode)` — source is a native user field, a custom profile
  field or a specific cohort; mode is keep-together or keep-apart. Every rule
  always applies (implicit AND); list position is the priority (splits and
  violations hit the lowest-priority rule first). Boolean OR/nesting was
  evaluated against core_availability and rejected: availability trees are
  per-user predicates, affinity rules are pair relations — OR-together forces
  transitive closure (giant clusters), OR-apart has no graph semantics, and
  mixed-mode OR is vacuous. Boolean logic remains reserved (schema version
  hinge `"v"`) for per-rule *scope matchers* in a future version, the one
  place it is well defined. No backward compatibility: the legacy
  `affinityfield`/`affinitymode` pair is removed everywhere.
- **Rule cap is a validation guardrail, not a performance boundary** (default
  10, admin-configurable). Allocation compute is ~linear in rules; the async
  trigger stays the apply write volume. The compute is never parallelised —
  the greedy engine is order-dependent and preview/apply must produce the
  same plan bit for bit.
- **Future-start enrolments option** (`includefuture`): includes enrolments
  that are active but not yet started — distinct from suspended, which stay
  out. Core's `get_enrolled_join()` cannot relax only the time window, so the
  plugin adds its own EXISTS predicate (with the SITEID exemption). Gated on
  `moodle/course:viewsuspendedusers`; inert when "only active" is unchecked.
- **Audit log = a snapshot, not references.** Every apply records who ran it,
  the ruleset with labels resolved at the time, each participant's per-rule
  values and per-member write outcome, in the plugin's first two DB tables.
  Explanations ("why here?") derive from the stored facts, never from
  replaying the engine. Privacy provider becomes full metadata + request +
  userlist with pseudonymising deletes; retention setting + cleanup task;
  purge on `course_deleted` (the recycle bin stores a backup file, not the
  course); entry lives in the course **Reports** section (`coursereports`
  settings-navigation container); the log travels in course backups only when
  "Include course logs" is ticked (excluded under anonymised backups).
- **sticky_footer carries action buttons only** — explanatory notes render in
  the page content area, never in the footer.

## v2 backlog (recorded 2026-08-13, none scheduled)

Ideas approved for a future major version. Each one was weighed during the
multi-rule design round and deliberately deferred so v1 could ship the flat
AND-only ruleset:

- **Friend-circle chaining (union-find).** Keep-together clusters by
  identical composite value tuples today. A chaining mode would union
  transitive pairs — A–B and B–C put A, B and C in one circle even when A and
  C share no value — via a union-find pass before packing. This is the
  semantics "OR-together" was actually asking for, delivered as an explicit
  per-rule flag instead of boolean algebra.
- **Existing group/grouping membership as a rule source.** "Keep together by
  current group in grouping X" or "keep apart the members of group Y":
  membership columns resolved the same way cohort sources are, with the same
  never-enumerate discipline on large courses.
- **Per-rule boolean scope matchers.** The one sound place for
  availability-style boolean UX: a matcher tree deciding *which* members a
  rule applies to (e.g. only students with country = BR), while the rule
  itself stays a plain (source, mode) pair. The `rulesjson` envelope is
  versioned (`"v"`) precisely so this can land without a migration.
- **Anti-isolation mode (CATME-style).** Avoid leaving exactly one member of
  a minority value alone in a group (e.g. a single woman on a team): a soft
  constraint preferring two-or-none per group, evaluated after the
  together/apart rules.
