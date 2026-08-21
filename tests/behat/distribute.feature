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
      | name     | course | idnumber |
      | Group A  | C1     | GA       |
      | Group B  | C1     | GB       |
      | Lab team | C1     | LAB      |
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

  Scenario: A second run over the same groups says why it would add nobody
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Distribute participants" "button"
    And I press "Preview distribution"
    # A run that writes must NOT show the explanation. Nothing else asserts
    # that the card actually hides: PHPUnit sees the payload, not the DOM.
    Then I should not see "This run would not add anyone"
    And I click on "Apply distribution" "button"
    Then I should see "Distribution applied: 4 memberships across 2 groups."
    # Everyone now sits in a selected group and the keep-grouped filter is on
    # by default, so the second run has an empty candidate list.
    When I set the field "Groups" to "Group A (2),Group B (2)"
    And I click on "Distribute participants" "button"
    And I press "Preview distribution"
    Then I should see "This run would not add anyone"
    And I should see "there is nobody to distribute"
    And I should see "so anyone a previous run already placed is left out"
    And the "Apply distribution" "button" should be disabled
    And I should not see "Distribution sample"

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

  Scenario: Build a rule sourced from an existing course group and keep it across a round trip
    Given the following "group members" exist:
      | user     | group |
      | student1 | LAB   |
      | student2 | LAB   |
    And I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Distribute participants" "button"
    And I click on "Add rule" "button"
    And I set the field "Rule 1 type" to "Group"
    And I set the field "Rule 1 group" to "Lab team"
    And I set the field "Rule 1 strategy" to "Keep apart"
    And I press "Preview distribution"
    Then I should see "1 · Keep apart: Group: Lab team"
    And I should see "Rules report"
    # Back and adjust re-hydrates the builder from the stored rules, which is
    # the only path through kindOf(): a source key it does not recognise
    # renders into the FIELD select, which does not contain it, and the rule
    # silently loses its source.
    When I press "Back and adjust"
    Then the field "Rule 1 type" matches value "Group"
    And the field "Rule 1 group" matches value "Lab team"
    And the field "Rule 1 strategy" matches value "Keep apart"

  Scenario: A group this run writes into cannot be its own rule source
    Given I am on the "Course 1" "groups" page logged in as "teacher1"
    When I set the field "Groups" to "Group A (0),Group B (0)"
    And I click on "Distribute participants" "button"
    And I click on "Add rule" "button"
    And I set the field "Rule 1 type" to "Group"
    Then I should see "Group A — a destination of this run (unavailable)"
    And I should see "Lab team"
    # The marker is cosmetic; the guard is the disabled attribute. Core has no
    # "option" selector and no negative form of this step, so both directions
    # are asserted with xpath_element plus should be disabled/enabled.
    And the "//select[@data-action='source']/option[contains(., 'a destination of this run (unavailable)')]" "xpath_element" should be disabled
    And the "//select[@data-action='source']/option[contains(., 'Lab team')]" "xpath_element" should be enabled
    # Unticking the filter makes those members take part, so the same group
    # becomes a usable source and the picker has to follow it live.
    When I set the field "Ignore users already in the selected groups" to "0"
    Then I should see "Group A — also a destination of this run"
    And the "//select[@data-action='source']/option[contains(., 'also a destination of this run')]" "xpath_element" should be enabled
