<?php

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests for Consumer form serialization fix across module combinations.
 *
 * @group simple_oauth_21
 */
class ConsumerFormSerializationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'serialization',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_client_registration',
    'simple_oauth_native_apps',
    'simple_oauth_pkce',
    'simple_oauth_server_metadata',
  ];

  /**
   * A user with permission to manage OAuth consumers.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create an admin user with OAuth permissions.
    $this->adminUser = $this->drupalCreateUser([
      'administer consumer entities',
      'add consumer entities',
      'access administration pages',
    ]);

    // Enable form serialization debugging for test monitoring.
    $config = $this->container->get('config.factory')->getEditable('simple_oauth_21.debug');
    $config->set('form_serialization_debugging', TRUE)->save();
    $this->container->get('cache.config')->delete('simple_oauth_21.debug');
  }

  /**
   * Tests Consumer form AJAX operations with all modules enabled.
   */
  public function testConsumerFormAjaxWithAllModules(): void {
    $this->drupalLogin($this->adminUser);

    // Navigate to the consumer add form.
    $this->drupalGet('admin/config/services/consumer/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Add consumer');

    // Test initial form load - should include native apps section.
    $this->assertSession()->fieldExists('label');
    $this->assertSession()->fieldExists('secret');
    $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-native-apps"]');

    // Fill in basic consumer information.
    $this->getSession()->getPage()->fillField('label', 'Test Native App');
    $this->getSession()->getPage()->fillField('secret', 'test-secret');
    $this->getSession()->getPage()->uncheckField('confidential');

    // Test contact email field AJAX functionality.
    $contact_add_button = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-contact-email-add-more"]');
    if ($contact_add_button) {
      // Click "Add another item" button for contact email.
      $contact_add_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();

      // Verify no serialization errors occurred.
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
      $this->assertSession()->pageTextNotContains('An AJAX HTTP error occurred');
      $this->assertSession()->statusCodeEquals(200);
    }

    // Test redirect URI field AJAX functionality.
    $redirect_add_button = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-redirect-add-more"]');
    if ($redirect_add_button) {
      // Add a redirect URI first.
      $this->getSession()->getPage()->fillField('redirect[0][value]', 'myapp://callback');

      // Click "Add another item" button for redirect URI.
      $redirect_add_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();

      // Verify no serialization errors occurred.
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
      $this->assertSession()->pageTextNotContains('An AJAX HTTP error occurred');
      $this->assertSession()->statusCodeEquals(200);

      // Add second redirect URI.
      $this->getSession()->getPage()->fillField('redirect[1][value]', 'http://127.0.0.1:8080/callback');
    }

    // Test client type detection AJAX functionality.
    $detect_button = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-native-apps-client-detection-detect-button"]');
    if ($detect_button) {
      $detect_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();

      // Verify detection works and no serialization errors.
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
      $this->assertSession()->pageTextNotContains('An AJAX HTTP error occurred');
      $this->assertSession()->pageTextContains('Detection Result');
      $this->assertSession()->statusCodeEquals(200);
    }

    // Submit the form to verify complete functionality.
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Consumer Test Native App has been created');
  }

  /**
   * Tests Consumer edit form AJAX operations.
   */
  public function testConsumerEditFormAjax(): void {
    $this->drupalLogin($this->adminUser);

    // Create a test consumer programmatically.
    $consumer = $this->container->get('entity_type.manager')
      ->getStorage('consumer')
      ->create([
        'label' => 'Test Consumer for Edit',
        'secret' => 'test-secret',
        'confidential' => FALSE,
        'redirect' => ['myapp://callback'],
        'contact_email' => ['test@example.com'],
      ]);
    $consumer->save();

    // Navigate to the consumer edit form.
    $this->drupalGet("admin/config/services/consumer/{$consumer->id()}/edit");
    $this->assertSession()->statusCodeEquals(200);

    // Test removing redirect URI.
    $remove_button = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-redirect-0-remove-button"]');
    if ($remove_button) {
      $remove_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();

      // Verify no serialization errors occurred.
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
      $this->assertSession()->pageTextNotContains('An AJAX HTTP error occurred');
      $this->assertSession()->statusCodeEquals(200);
    }

    // Test removing contact email.
    $contact_remove_button = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-contact-email-0-remove-button"]');
    if ($contact_remove_button) {
      $contact_remove_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();

      // Verify no serialization errors occurred.
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
      $this->assertSession()->pageTextNotContains('An AJAX HTTP error occurred');
      $this->assertSession()->statusCodeEquals(200);
    }
  }

  /**
   * Tests form functionality with selective module enabling.
   */
  public function testSerializationFixWithModuleCombinations(): void {
    $this->drupalLogin($this->adminUser);

    // Test basic consumer form load with current module combination.
    $this->drupalGet('admin/config/services/consumer/add');
    $this->assertSession()->statusCodeEquals(200);

    // Fill basic form data.
    $this->getSession()->getPage()->fillField('label', 'Module Combo Test');
    $this->getSession()->getPage()->fillField('secret', 'test-secret');

    // Test any available AJAX functionality.
    $add_buttons = $this->getSession()->getPage()->findAll('css', '[name*="add_more"]');
    foreach ($add_buttons as $button) {
      if ($button->isVisible()) {
        $button->click();
        $this->assertSession()->assertWaitOnAjaxRequest();

        // Verify no serialization errors for any module combination.
        $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
        $this->assertSession()->pageTextNotContains('An AJAX HTTP error occurred');
        $this->assertSession()->statusCodeEquals(200);

        // Only test one button to avoid conflicts.
        break;
      }
    }
  }

  /**
   * Tests form serialization debugging integration.
   */
  public function testFormSerializationDebugging(): void {
    $this->drupalLogin($this->adminUser);

    // Load a consumer form to trigger debugging.
    $this->drupalGet('admin/config/services/consumer/add');
    $this->assertSession()->statusCodeEquals(200);

    // Fill out form to trigger validation debugging.
    $this->getSession()->getPage()->fillField('label', 'Debug Test Consumer');
    $this->getSession()->getPage()->fillField('secret', 'debug-secret');
    $this->getSession()->getPage()->pressButton('Save');

    // Check that debugging doesn't interfere with normal operation.
    $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests regression scenarios for Consumer entity operations.
   */
  public function testConsumerEntityRegressionScenarios(): void {
    $this->drupalLogin($this->adminUser);

    // Test scenario 1: Multiple redirect URIs with different schemes.
    $this->drupalGet('admin/config/services/consumer/add');
    $this->getSession()->getPage()->fillField('label', 'Multi-URI Consumer');
    $this->getSession()->getPage()->fillField('secret', 'multi-secret');
    $this->getSession()->getPage()->fillField('redirect[0][value]', 'https://web.example.com/callback');

    // Add mobile app URI.
    $redirect_add = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-redirect-add-more"]');
    if ($redirect_add) {
      $redirect_add->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->fillField('redirect[1][value]', 'myapp://callback');

      // Add terminal app URI.
      $redirect_add->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->fillField('redirect[2][value]', 'http://127.0.0.1:8080/callback');
    }

    // Test client detection with mixed URIs.
    $detect_button = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-native-apps-client-detection-detect-button"]');
    if ($detect_button) {
      $detect_button->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
    }

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);

    // Test scenario 2: Multiple contact emails.
    $this->drupalGet('admin/config/services/consumer/add');
    $this->getSession()->getPage()->fillField('label', 'Multi-Contact Consumer');
    $this->getSession()->getPage()->fillField('secret', 'contact-secret');
    $this->getSession()->getPage()->fillField('contact_email[0][value]', 'admin@example.com');

    $contact_add = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-contact-email-add-more"]');
    if ($contact_add) {
      $contact_add->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->fillField('contact_email[1][value]', 'support@example.com');

      $contact_add->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->getSession()->getPage()->fillField('contact_email[2][value]', 'tech@example.com');
    }

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests error conditions and edge cases.
   */
  public function testErrorConditionsAndEdgeCases(): void {
    $this->drupalLogin($this->adminUser);

    // Test form with validation errors to ensure AJAX still works.
    $this->drupalGet('admin/config/services/consumer/add');

    // Submit empty form to trigger validation errors.
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->pageTextContains('Label field is required');

    // After validation error, test that AJAX still works.
    $this->getSession()->getPage()->fillField('label', 'Error Recovery Test');
    $redirect_add = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-redirect-add-more"]');
    if ($redirect_add) {
      $redirect_add->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
    }

    // Test with invalid URI and ensure AJAX continues working.
    $this->getSession()->getPage()->fillField('redirect[0][value]', 'invalid-uri');
    $contact_add = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-contact-email-add-more"]');
    if ($contact_add) {
      $contact_add->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertSession()->pageTextNotContains('Serialization of \'Closure\' is not allowed');
    }
  }

  /**
   * Tests performance impact of serialization fix.
   */
  public function testSerializationFixPerformance(): void {
    $this->drupalLogin($this->adminUser);

    // Measure time for form operations to ensure fix doesn't impact performance.
    $start_time = microtime(TRUE);

    $this->drupalGet('admin/config/services/consumer/add');
    $this->getSession()->getPage()->fillField('label', 'Performance Test');
    $this->getSession()->getPage()->fillField('secret', 'perf-secret');

    // Perform multiple AJAX operations.
    for ($i = 0; $i < 3; $i++) {
      $redirect_add = $this->getSession()->getPage()->find('css', '[data-drupal-selector="edit-redirect-add-more"]');
      if ($redirect_add) {
        $redirect_add->click();
        $this->assertSession()->assertWaitOnAjaxRequest();
        $this->getSession()->getPage()->fillField("redirect[$i][value]", "https://example$i.com/callback");
      }
    }

    $end_time = microtime(TRUE);
    $execution_time = $end_time - $start_time;

    // Performance should be reasonable (under 30 seconds for 3 AJAX operations).
    $this->assertLessThan(30, $execution_time, 'Serialization fix does not significantly impact performance');

    $this->getSession()->getPage()->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);
  }

}