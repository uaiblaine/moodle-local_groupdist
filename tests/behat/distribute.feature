@local @local_groupdist @javascript
Feature: Distribute participants into selected groups
  In order to fill course groups quickly and fairly
  As a teacher
  I need a bulk action that distributes participants into the groups I select

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Terry     | Teacher  |
      | student1 | Sam       | One      |
      | student2 | Sue       | Two      |
      | student3 | Sid       | Three    |
      | student4 | Sol       | Four     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |

  Scenario: The injected button enables with a selection and opens the options form
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    Then the "Distribute participants" "button" should be disabled
    When I set the field "Groups" to "Group A (0)"
    Then the "Distribute participants" "button" should be enabled
    When I click on "Distribute participants" "button"
    Then I should see "Selected groups (1)"
    And I should see "Seats and overbooking"

  Scenario: Preview and apply a distribution into two groups
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Distribute participants" "button"
    And I press "Preview distribution"
    And I should see "Showing 2 of 2 groups"
    And I click on "Apply distribution" "button"
    Then I should see "Distribution applied: 4 memberships across 2 groups."
