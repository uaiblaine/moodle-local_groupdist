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

$string['affinityfield'] = 'Group by profile field';
$string['affinityfield_help'] = 'Distributes participants so the chosen strategy applies to the value of this field. Native user fields and custom profile fields appear together in the list. Participants without a value in the field are allocated randomly and reported in the preview.';
$string['affinitymode'] = 'Affinity strategy';
$string['affinitymode_help'] = 'Keep together: participants with the same value land in the same group. Keep apart: the allocator avoids repeating the same value inside a group. Both apply to the participants being distributed in this run; values of members a group already has are not considered.';
$string['affinitymodeapart'] = 'Keep apart — avoid participants with the same value in the same group';
$string['affinitymodetogether'] = 'Keep together — participants with the same value go to the same group';
$string['affinitynone'] = 'Do not group (default)';
$string['affinitysection'] = 'Affinity by profile field';
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
$string['backadjust'] = 'Back and adjust';
$string['bulkeditfootnote'] = 'Only custom fields are saved here. Name, description and other settings open under "Edit".';
$string['bulkeditgroups'] = 'Bulk edit groups';
$string['chosenoptions'] = 'Chosen options';
$string['cleanupfieldsonuninstall'] = 'Remove group fields on uninstall';
$string['cleanupfieldsonuninstall_desc'] = 'If enabled, uninstalling the plugin deletes the group custom fields it provisioned (seats capacity and location) together with the values stored in them for every group. If disabled, the fields and their data are kept and can be managed under group custom fields.';
$string['columnsbutton'] = 'Columns';
$string['columnsmenuhead'] = 'Group and "{$a}" stay visible. Other group custom fields appear in this list automatically.';
$string['distributeparticipants'] = 'Distribute participants';
$string['editgroupsettings'] = 'Group settings — {$a}';
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
$string['groupnewmembers'] = '{$a} new members';
$string['idnumbercolumn'] = 'ID';
$string['ignoregrouped'] = 'Ignore users already in the selected groups';
$string['ignoregrouped_help'] = 'When enabled, participants who already belong to one of the selected groups keep their place and are not distributed again. When disabled, they take part in the allocation and may be added to further groups.';
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
$string['noseatsnote'] = '{$a->noseats} of the {$a->total} selected groups have no "{$a->field}" value — they are treated as unlimited.';
$string['nothingtoapply'] = 'The distribution would not add any members, so nothing was applied.';
$string['overbook'] = 'Overbooking per group';
$string['overbook_help'] = 'Extra participants allowed beyond the declared seats, per group, when the seats do not suffice for everyone. 0 disables overbooking.';
$string['pluginname'] = 'Group distribution';
$string['previewcapped'] = 'Preview limited to {$a->cap} of {$a->total} groups. Applying the distribution covers all groups normally.';
$string['previewdistribution'] = 'Preview distribution';
$string['previewnothingsaved'] = 'Preview of the distribution into {$a} selected groups. Nothing has been saved yet — memberships are only written when you apply.';
$string['previewstale'] = 'Enrolments or groups changed while previewing. Reload the preview before applying.';
$string['privacy:metadata:preference:bulkeditcols'] = 'Columns the user collapsed on the bulk edit groups table.';
$string['recapaffinity'] = 'Field: {$a}';
$string['recapcohort'] = 'Cohort: {$a}';
$string['recapoverbook'] = 'Overbooking: up to +{$a} per group';
$string['recaprole'] = 'Role: {$a}';
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
$string['unsavedchanges'] = '{$a} group(s) with unsaved changes';
$string['useseats'] = 'Respect the groups\' "{$a}" field';
$string['useseats_help'] = 'Each group receives at most the number of participants declared in its "{$a}" custom field (minus current members, plus overbooking). Groups without a value are treated as unlimited.';
$string['warningapart'] = '"{$a->value}" has more participants than available groups; {$a->count} placements repeat the value inside a group.';
$string['warningcommslow'] = 'The communication subsystem is enabled: every membership triggers a room sync, so applying may take considerably longer.';
$string['warningnoseats'] = '{$a->count} selected groups declare no "{$a->field}" value and are treated as unlimited.';
$string['warningnovalue'] = '{$a->count} participants have no value in "{$a->field}" and were allocated without the affinity rule.';
$string['warningsplit'] = 'Participants with "{$a->value}" did not fit one group and were split across {$a->count} groups.';
$string['warningunassigned'] = '{$a} participants could not be placed — every group is at capacity. Increase seats or overbooking.';
