# Review context for local_groupdist

`local_groupdist` adds a "Distribute participants" bulk action to a course's group
management page. It distributes enrolled users into existing groups, keeps an audit
log, and adds a bulk edit table for the groups' custom fields. It is a local plugin with
no front-page or guest surface.

## Who is trusted

- Site administrators (`moodle/site:config`) are fully trusted.
- A user holding `local/groupdist:distribute` or `local/groupdist:viewauditlog` in the
  course context is a trusted course manager. Both capabilities are declared with
  `RISK_PERSONAL` and default to `editingteacher` and `manager`. The bulk edit page, its
  web service and the group settings modal are gated by `moodle/course:managegroups`.
- Every enrolled participant is untrusted. So are profile field values, group names,
  cohort names and custom field names, which a teacher or the participant can set: treat
  them as markup-bearing input at every output.
- Course managers are trusted to choose which groups and which rules a run uses, not to
  read groups they cannot see: group visibility (`visibility`) and
  `moodle/course:viewhiddengroups` still decide what a manager may see or write into.

## Surfaces

- Web services, all `ajax`, session-based: `get_preview`, `search_cohorts` and
  `search_groups` (distribute), `get_audit_sections` and `get_audit_members`
  (viewauditlog), `save_group_fields` (managegroups). Contexts are derived server-side
  from course and group ids; a raw context id is never accepted.
- Page scripts: `distribute.php`, `apply.php`, `status.php`, `audit.php`, `bulkedit.php`.
- Two tables, `local_groupdist_run` and `local_groupdist_run_user`, hold an audit
  snapshot. Deleting a user pseudonymises their rows instead of removing them.
- Memberships are written only through `groups_add_member()` with the plugin's component
  and the run's seed as `itemid`; runs above 500 memberships go through an adhoc task.

## Facts that look like findings but are by design

- **Group visibility is resolved by the plugin, not by `groups_get_all_groups()`.** That
  core helper fails open on a cold cache and returns hidden groups.
  `distribution::get_destination_groups()` and `profilefields::get_source_groups()` state
  the visibility rule themselves. A new call to `groups_get_all_groups()` for authorisation
  is a finding; `groups_get_group()` and `groups_group_visible()` are not visibility checks.
- **Names have two spellings.** `local\plaintext::format()` returns plain text for sinks
  that escape for themselves (Mustache double stash, `textContent`, `PARAM_TEXT` web
  service fields). Moodle form rows, labels and selects render raw, so they take the
  escaped spelling. A raw name in a triple stash or in `PARAM_TEXT` output is a finding.
- **A preview is recomputed, never stored.** `apply.php` re-runs the plan from the posted
  options and seed and refuses when the fingerprint differs, when the seed is spent, or
  when the run already completed. The fingerprint must cover every input of the plan.
- **Bulk edit shows a password-type text field as plain text**, because the page needs
  `moodle/course:managegroups` and core's own group form already puts the stored value in
  the password input for the same users. Accepted.
- Cohort ids from a request are validated with `cohort_get_cohort()` against the course
  context, so a hidden cohort is not a membership oracle.

## De-emphasise

- `amd/build/**` is minified output of `amd/src/**`; review the source.
- `tests/**`, `lang/**` and `docs/**` carry no production behaviour.
- One branch per Moodle version (`main` is 5.2). The 4.5 branch contains compatibility code
  for a core that lacks some helpers, which is deliberate.
