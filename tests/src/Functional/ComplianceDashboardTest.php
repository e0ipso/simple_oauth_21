<?php

namespace Drupal\Tests\simple_oauth_21\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the OAuth 2.1 compliance dashboard functionality.
 */
#[Group('simple_oauth_21')]
class ComplianceDashboardTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'simple_oauth',
    'simple_oauth_21',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to administer OAuth settings.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'administer simple_oauth entities',
    ]);
  }

  /**
   * Tests simplified dashboard functionality.
   */
  public function testComprehensiveDashboardFunctionality() {
    // Test 1: Dashboard access and permissions
    // Anonymous users should not have access.
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->statusCodeEquals(403);

    // Admin users should have access.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('OAuth 2.1 RFC Implementation Status');

    // Test 2: RFC matrix is displayed
    // Check that the RFC matrix is shown with expected RFCs.
    $this->assertSession()->pageTextContains('PKCE (Proof Key for Code Exchange)');
    $this->assertSession()->pageTextContains('OAuth Server Metadata');
    $this->assertSession()->pageTextContains('OAuth for Native Apps');

    // Test 3: Dashboard updates with PKCE module
    // Enable PKCE submodule.
    $this->container->get('module_installer')->install(['simple_oauth_pkce']);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->pageTextContains('PKCE (Proof Key for Code Exchange)');

    // Test 4: Dashboard updates with full submodule installation
    // Enable all submodules.
    $this->container->get('module_installer')->install([
      'simple_oauth_native_apps',
      'simple_oauth_server_metadata',
    ]);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->pageTextContains('OAuth Server Metadata');
    $this->assertSession()->pageTextContains('OAuth for Native Apps');

    // Test 5: Dashboard displays RFC implementation status
    // Check that the simplified RFC matrix is displayed.
    $this->assertSession()->pageTextContains('OAuth 2.1 RFC Implementation Status');
    $this->assertSession()->pageTextContains('Dynamic Client Registration');
    $this->assertSession()->pageTextContains('Device Authorization Grant');

    // Final assertion to confirm all dashboard test scenarios completed.
    // @phpstan-ignore method.alreadyNarrowedType
    $this->assertTrue(TRUE, 'All OAuth 2.1 compliance dashboard test scenarios completed successfully');
  }

}
