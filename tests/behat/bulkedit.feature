@local @local_groupdist @javascript
Feature: Bulk edit group custom fields
  In order to keep group capacity data complete
  As a teacher
  I need to edit the custom fields of many groups at once

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |

  Scenario: Mass apply seats, save, and verify persistence
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Bulk edit groups" "button"
    Then I should see "Bulk actions"
    When I set the field "local-groupdist-massvalue" to "9"
    And I click on "Apply to all" "button"
    And I click on "Save changes" "button"
    Then I should see "Saved 2 changes."
    When I am on the "Course 1" "groups" page
    And I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Bulk edit groups" "button"
    Then "//tr[contains(., 'Group A')]//input[@data-fieldtype='number'][@value='9']" "xpath_element" should exist
    And "//tr[contains(., 'Group B')]//input[@data-fieldtype='number'][@value='9']" "xpath_element" should exist

  Scenario: Leaving with unsaved cells is confirmed, and saved work is not
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Bulk edit groups" "button"
    And I set the field "local-groupdist-massvalue" to "4"
    And I click on "Apply to all" "button"
    Then I should see "2 group(s) with unsaved changes"
    When I click on "Back to groups" "link"
    Then I should see "Unsaved changes"
    When I click on "Leave and discard" "button" in the "Unsaved changes" "dialogue"
    Then I should see "Bulk edit groups"

  Scenario: Once saved, the way back does not ask again
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0)"
    And I click on "Bulk edit groups" "button"
    And I set the field "local-groupdist-massvalue" to "6"
    And I click on "Apply to all" "button"
    And I click on "Save changes" "button"
    Then I should see "Saved 1 changes."
    When I click on "Back to groups" "link"
    Then I should see "Groups"
    And I should not see "Unsaved changes"

  Scenario: Edit one group's settings through the modal
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0)"
    And I click on "Bulk edit groups" "button"
    And I click on "Edit" "button" in the "Group A" "table_row"
    And I set the field "Group name" to "Group Alpha"
    And I set the field "Group ID number" to "ZED-9"
    And I click on "Save changes" "button" in the "Group settings — Group A" "dialogue"
    Then I should see "Group Alpha"
    And I should see "ZED-9" in the "Group Alpha" "table_row"
    And I should not see "GA" in the "Group Alpha" "table_row"

  Scenario: The settings modal offers every native group setting
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0)"
    And I click on "Bulk edit groups" "button"
    And I click on "Edit" "button" in the "Group A" "table_row"
    Then I should see "Enrolment key" in the "Group settings — Group A" "dialogue"
    And I should see "Group membership visibility" in the "Group settings — Group A" "dialogue"
    And I should see "Current picture" in the "Group settings — Group A" "dialogue"
    And I should see "New picture" in the "Group settings — Group A" "dialogue"
    When I set the field "Group membership visibility" to "Only visible to members"
    And I click on "Save changes" "button" in the "Group settings — Group A" "dialogue"
    And I click on "Edit" "button" in the "Group A" "table_row"
    Then the field "Group membership visibility" matches value "Only visible to members"

  Scenario: A group that already has members cannot have its visibility changed
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
    And I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (1),Group B (0)"
    And I click on "Bulk edit groups" "button"
    And I click on "Edit" "button" in the "Group A" "table_row"
    Then I should see "Group membership visibility" in the "Group settings — Group A" "dialogue"
    And "Group membership visibility" "select" should not exist in the "Group settings — Group A" "dialogue"
    When I click on "Cancel" "button" in the "Group settings — Group A" "dialogue"
    And I click on "Edit" "button" in the "Group B" "table_row"
    Then "Group membership visibility" "select" should exist in the "Group settings — Group B" "dialogue"
