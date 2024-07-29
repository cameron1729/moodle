@core
Feature: Navigate using the browser back button
  In order to easily navigate Moodle
  As a user
  Pressing the back button in the browser should result in accurate navigation menus

  @javascript
  Scenario: The active menu item check marks are consistent - topics format course
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    When I am on the "Course 1" course page logged in as "admin"
    And I update the link with selector 'li[data-key="questionbank"] > a' to go nowhere
    And I navigate to "Question bank" in current page administration
    Then "li[data-key='questionbank'] > a[aria-current='true']" "css_element" should not exist
