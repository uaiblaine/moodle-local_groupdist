<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language strings.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addrule'] = 'Add rule';
$string['affinitynone'] = 'Do not group (default)';
$string['affinitysection'] = 'Affinity';
$string['allocationsection'] = 'Allocation';
$string['appliedsummary'] = 'Distribution applied: {$a->added} memberships across {$a->groups} groups.';
$string['applydistribution'] = 'Apply distribution';
$string['applyfinished'] = 'The distribution run has finished. Check the groups page for the result.';
$string['applyfootnote'] = 'Nothing is saved until you apply. Large runs are applied in the background and show a progress bar.';
$string['applymessagestale'] = 'Participant distribution NOT applied';
$string['applymessagestalebody'] = 'Enrolments or groups changed between your preview and the background run, so nothing was written. Please preview and apply the distribution again.';
$string['applymessagesuccess'] = 'Participant distribution applied';
$string['applymessagesuccessbody'] = 'The background distribution finished: {$a->added} memberships across {$a->groups} groups.';
$string['applyprogress'] = 'Writing group memberships';
$string['applyrunning'] = 'The distribution is being applied in the background. You can leave this page; memberships keep being written.';
$string['applytoall'] = 'Apply to all';
$string['auditby'] = 'Applied by';
$string['auditgroupdeleted'] = 'group since deleted';
$string['auditlog'] = 'Distribution log';
$string['auditmaskedvalue'] = 'value hidden from you';
$string['auditmeta'] = 'seed {$a->seed} · plugin {$a->version} · {$a->written} of {$a->total} memberships written';
$string['auditnogroup'] = 'Without a group';
$string['auditnoruns'] = 'No distributions have been applied in this course yet.';
$string['auditoutcomefailed'] = 'failed';
$string['auditoutcomeplanned'] = 'planned';
$string['auditoutcomeskipped'] = 'no write needed';
$string['auditoutcomeunassigned'] = 'no seat';
$string['auditoutcomewritten'] = 'written';
$string['auditremoved'] = 'Removed participant';
$string['auditretentiondays'] = 'Audit log retention (days)';
$string['auditretentiondays_desc'] = 'Distribution audit runs older than this are deleted by a daily scheduled task. 0 keeps the log forever.';
$string['auditrules'] = 'Rules applied (labels as of apply time)';
$string['auditrun'] = 'Run of {$a}';
$string['auditsnapshotnote'] = 'Snapshot: everything below shows the data as it was at apply time. Later changes to profiles, cohorts or groups do not alter this record.';
$string['auditstatus'] = 'Status';
$string['auditstatusaborted'] = 'Aborted';
$string['auditstatuscompleted'] = 'Completed';
$string['auditstatuspartial'] = 'Partial';
$string['auditstatuspending'] = 'Pending';
$string['auditwhen'] = 'When';
$string['auditwhy'] = 'Why here?';
$string['auditwhyapart'] = 'Rule {$a->index} · Keep apart · {$a->label}: separated from {$a->others}.';
$string['auditwhyapartsame'] = 'Rule {$a->index} · Keep apart · {$a->label}: shares the group with {$a->others}.';
$string['auditwhymasked'] = 'Rule {$a->index} · {$a->label}: value hidden from you.';
$string['auditwhynone'] = 'No rule constrained this participant.';
$string['auditwhytogether'] = 'Rule {$a->index} · Keep together · {$a->label}: value "{$a->value}" — placed with {$a->count} more of the same value.';
$string['auditwritten'] = 'Written';
$string['backadjust'] = 'Back and adjust';
$string['bulkeditfootnote'] = 'Only custom fields are saved here. Name, description and other settings open under "Edit".';
$string['bulkeditgroups'] = 'Bulk edit groups';
$string['chosenoptions'] = 'Chosen options';
$string['cleanupfieldsonuninstall'] = 'Remove group fields on uninstall';
$string['cleanupfieldsonuninstall_desc'] = 'If enabled, uninstalling the plugin deletes the group custom fields it provisioned (seats capacity and location) together with the values stored in them for every group. If disabled, the fields and their data are kept and can be managed under group custom fields.';
$string['cohortsourcelabel'] = 'Cohort: {$a}';
$string['columnsbutton'] = 'Columns';
$string['columnsmenuhead'] = 'Group and "{$a}" stay visible. Other group custom fields appear in this list automatically.';
$string['distributeparticipants'] = 'Distribute participants';
$string['editgroupsettings'] = 'Group settings — {$a}';
$string['enrolstartbadge'] = 'starts {$a}';
$string['errornogroups'] = 'Select at least one existing group to distribute participants into.';
$string['errornogroupsedit'] = 'Select at least one existing group to edit.';
$string['erroroverbookrange'] = 'Overbooking must be between 0 and 99.';
$string['errorstale'] = 'Enrolments or groups changed since the preview, so nothing was applied. Please run the distribution again.';
$string['errortaskpending'] = 'A distribution is already being applied for this course. Wait for it to finish before starting another one.';
$string['event_distribution_applied'] = 'Distribution applied';
$string['fieldcategory'] = 'Distribution';
$string['fieldlocation'] = 'Location';
$string['fieldseats'] = 'Seats';
$string['filternoseats'] = 'Only groups without "{$a}"';
$string['groupdist:distribute'] = 'Distribute participants into course groups';
$string['groupdist:viewauditlog'] = 'View the distribution audit log';
$string['groupnewmembers'] = '{$a} new members';
$string['idnumbercolumn'] = 'ID';
$string['ignoregrouped'] = 'Ignore users already in the selected groups';
$string['ignoregrouped_help'] = 'When enabled, participants who already belong to one of the selected groups keep their place and are not distributed again. When disabled, they take part in the allocation and may be added to further groups.';
$string['includefutureenrol'] = 'Include future-start enrolments';
$string['includefutureenrol_help'] = 'Active enrolments whose start date still lies in the future join the distribution — unlike suspended or expired enrolments, which always stay out. Only effective while "Include only active enrolments" is ticked, and only for users allowed to see non-current participants.';
$string['legenddirty'] = 'unsaved change';
$string['legendnoseats'] = '"{$a}" not set';
$string['legendover'] = 'members above "{$a}"';
$string['loadmoregroups'] = 'Show more groups';
$string['massactions'] = 'Bulk actions';
$string['massapplyhint'] = 'Fills the table; nothing is stored until you save.';
$string['massapplyseats'] = 'Set "{$a}" of every group to';
$string['maxaffinityrules'] = 'Maximum affinity rules';
$string['maxaffinityrules_desc'] = 'Upper limit of affinity rules accepted per distribution. A validation guardrail — the allocation cost grows roughly linearly with the number of rules.';
$string['memberscolumn'] = 'Members';
$string['memberscolumnunit'] = 'members';
$string['membersnotshown'] = '+ {$a} members not shown in the sample';
$string['messageprovider:applyresult'] = 'Outcome of a background participant distribution';
$string['modeapart'] = 'Keep apart';
$string['modetogether'] = 'Keep together';
$string['noseatsnote'] = '{$a->noseats} of the {$a->total} selected groups have no "{$a->field}" value — they are treated as unlimited.';
$string['nothingtoapply'] = 'The distribution would not add any members, so nothing was applied.';
$string['overbook'] = 'Overbooking per group';
$string['overbook_help'] = 'Extra participants allowed beyond the declared seats, per group, when the seats do not suffice for everyone. 0 disables overbooking.';
$string['pluginname'] = 'Group distribution';
$string['previewcapped'] = 'Preview limited to {$a->cap} of {$a->total} groups. Applying the distribution covers all groups normally.';
$string['previewdistribution'] = 'Preview distribution';
$string['previewnothingsaved'] = 'Preview of the distribution into {$a} selected groups. Nothing has been saved yet — memberships are only written when you apply.';
$string['previewstale'] = 'Enrolments or groups changed while previewing. Reload the preview before applying.';
$string['privacy:metadata:local_groupdist_run'] = 'One snapshot per applied distribution run: who ran it and the inputs the plan was computed from.';
$string['privacy:metadata:local_groupdist_run:courseid'] = 'The course the distribution ran in.';
$string['privacy:metadata:local_groupdist_run:optionsjson'] = 'The distribution options as chosen at apply time.';
$string['privacy:metadata:local_groupdist_run:rulesjson'] = 'The affinity rules, with source labels resolved at apply time.';
$string['privacy:metadata:local_groupdist_run:status'] = 'The run outcome (completed, partial or aborted).';
$string['privacy:metadata:local_groupdist_run:timecreated'] = 'When the run was applied.';
$string['privacy:metadata:local_groupdist_run:userid'] = 'The user who applied the distribution (0 after pseudonymisation).';
$string['privacy:metadata:local_groupdist_run_user'] = 'Per-participant snapshot of a run: the rule values at apply time, the planned group and the write outcome.';
$string['privacy:metadata:local_groupdist_run_user:groupid'] = 'The group the participant was planned into.';
$string['privacy:metadata:local_groupdist_run_user:userid'] = 'The participant (0 after pseudonymisation).';
$string['privacy:metadata:local_groupdist_run_user:valuesjson'] = 'The participant\'s value for each rule at apply time (profile field values, cohort membership flags; blanked on pseudonymisation).';
$string['privacy:metadata:local_groupdist_run_user:writestatus'] = 'Whether the membership was written, failed, was unnecessary or found no seat.';
$string['privacy:metadata:preference:bulkeditcols'] = 'Columns the user collapsed on the bulk edit groups table.';
$string['recapcohort'] = 'Cohort: {$a}';
$string['recapoverbook'] = 'Overbooking: up to +{$a} per group';
$string['recaprole'] = 'Role: {$a}';
$string['recaprule'] = '{$a->index} · {$a->mode}: {$a->label}';
$string['rulechoosecohort'] = 'Choose the cohort…';
$string['rulechoosefield'] = 'Choose the field…';
$string['rulecohortlabel'] = 'Rule {$a} cohort';
$string['rulecohortsearchlabel'] = 'Rule {$a} cohort search';
$string['ruleconnector'] = 'and also';
$string['ruledelete'] = 'Remove rule {$a}';
$string['rulefieldlabel'] = 'Rule {$a} field';
$string['rulemodelabel'] = 'Rule {$a} strategy';
$string['rulemovedown'] = 'Move rule {$a} down';
$string['rulemoveup'] = 'Move rule {$a} up';
$string['rulereportheading'] = 'Rules report';
$string['rulereportmore'] = '… and {$a} more values';
$string['rulereportnovalue'] = '{$a} participants without a value';
$string['rulereportsplit'] = 'split across {$a} groups';
$string['rulereportviolations'] = '{$a} repeated inside a group';
$string['rulesearchnoresults'] = 'No matching cohorts';
$string['rulesprioritynote'] = 'Every rule applies at the same time (AND). The order sets the priority: when rules conflict, or a cluster must be split for lack of seats, the rule higher up prevails and the violation is counted against the one further down — always with a warning in the preview.';
$string['rulestatusrepeats'] = '{$a} repeated';
$string['rulestatusvalues'] = '{$a} values';
$string['ruletypecohort'] = 'Cohort';
$string['ruletypefield'] = 'Profile field';
$string['ruletypelabel'] = 'Rule {$a} type';
$string['samplesheading'] = 'Distribution sample — up to 5 participants per group';
$string['savedchanges'] = 'Saved {$a} changes.';
$string['savingprogress'] = 'Saving… ({$a->done}/{$a->total})';
$string['seatsignored'] = 'Seats not considered';
$string['seatssection'] = 'Seats and overbooking';
$string['selectedgroups'] = 'Selected groups ({$a})';
$string['selectedgroupsnote'] = 'The selection comes from the groups page. To change it, go back and select other groups.';
$string['showinggroups'] = 'Showing {$a->shown} of {$a->total} groups';
$string['stataverage'] = 'average new members per group';
$string['statcandidates'] = 'participants to distribute';
$string['statgroups'] = 'selected groups';
$string['statseats'] = 'declared seats + overbooking used';
$string['statusnew'] = 'New';
$string['statwarnings'] = 'warnings';
$string['task_apply_distribution'] = 'Apply participant distribution';
$string['task_cleanup_audit'] = 'Clean up expired distribution audit runs';
$string['unsavedchanges'] = '{$a} group(s) with unsaved changes';
$string['useseats'] = 'Respect the groups\' "{$a}" field';
$string['useseats_help'] = 'Each group receives at most the number of participants declared in its "{$a}" custom field (minus current members, plus overbooking). Groups without a value are treated as unlimited.';
$string['warningapart'] = '"{$a->value}" ({$a->field}) has more participants than available groups; {$a->count} placements repeat the value inside a group.';
$string['warningcommslow'] = 'The communication subsystem is enabled: every membership triggers a room sync, so applying may take considerably longer.';
$string['warningcontradiction'] = 'Keep-together and keep-apart rules bound the same participants; the higher-priority rule, on "{$a->field}", prevailed in {$a->count} case(s).';
$string['warningnoseats'] = '{$a->count} selected groups declare no "{$a->field}" value and are treated as unlimited.';
$string['warningnovalue'] = '{$a->count} participants have no value in "{$a->field}" and were allocated without the affinity rule.';
$string['warningsplit'] = 'Participants with "{$a->value}" did not fit one group and were split across {$a->count} groups.';
$string['warningunassigned'] = '{$a} participants could not be placed — every group is at capacity. Increase seats or overbooking.';
