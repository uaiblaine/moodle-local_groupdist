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
    And the following "cohorts" exist:
      | name    | idnumber |
      | Mentors | ME       |

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

  Scenario: The audit log records an applied distribution under course reports
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Distribute participants" "button"
    And I press "Preview distribution"
    And I click on "Apply distribution" "button"
    And I am on the "Course 1" "course" page
    And I navigate to "Reports" in current page administration
    And I click on "Distribution log" "link"
    Then I should see "Terry Teacher"
    And I should see "4 / 4"
    When I click on "View" "link"
    Then I should see "Applied by: Terry Teacher"
    And I should see "Group A"
    And I should see "Showing 2 of 2 groups"
    # The expected outcome carries no badge; only the exceptional ones do.
    # Scoped to the badge itself: the run meta line legitimately reads
    # "... memberships written", so a whole-page check would pass for the
    # wrong reason.
    And "//span[contains(@class, 'badge')][normalize-space() = 'written']" "xpath_element" should not exist

  Scenario: A group section opens with five participants and offers the rest
    Given the following "users" exist:
      | username | firstname | lastname |
      | student5 | Sky       | Five     |
      | student6 | Sal       | Six      |
      | student7 | Sky       | Seven    |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student5 | C1     | student |
      | student6 | C1     | student |
      | student7 | C1     | student |
    And I am on the "Course 1" "groups" page logged in as "teacher1"
    # One target group, so all seven participants land in the same section.
    When I set the field "Groups" to "Group A (0)"
    And I click on "Distribute participants" "button"
    And I press "Preview distribution"
    And I click on "Apply distribution" "button"
    And I am on the "Course 1" "course" page
    And I navigate to "Reports" in current page administration
    And I click on "Distribution log" "link"
    And I click on "View" "link"
    Then I should see "Show 2 more"
    And I should see "7"
    When I click on "Show 2 more" "link"
    Then I should not see "Show 2 more"

  Scenario: The audit log searches the run by participant and by group name
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Distribute participants" "button"
    And I press "Preview distribution"
    And I click on "Apply distribution" "button"
    And I am on the "Course 1" "course" page
    And I navigate to "Reports" in current page administration
    And I click on "Distribution log" "link"
    And I click on "View" "link"
    Then I should see "Sam One"
    And I should see "Sue Two"
    When I set the field "Search participants" to "Sam"
    And I press "Search"
    Then I should see "Sam One"
    And I should not see "Sue Two"
    When I set the field "Search participants" to ""
    And I set the field "Search groups" to "Group B"
    And I press "Search"
    Then I should see "Group B"
    And I should not see "Group A"

  Scenario: Build field and cohort affinity rules and see them echoed in the preview recap
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Distribute participants" "button"
    And I click on "Add rule" "button"
    And I set the field "Rule 1 field" to "City"
    And I set the field "Rule 1 strategy" to "Keep apart"
    And I click on "Add rule" "button"
    And I set the field "Rule 2 type" to "Cohort"
    And I set the field "Rule 2 cohort" to "Mentors"
    And I press "Preview distribution"
    Then I should see "1 · Keep apart: City"
    And I should see "2 · Keep together: Cohort: Mentors"
    And I should see "Rules report"
