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
   * Tests comprehensive dashboard functionality.
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
    $this->assertSession()->pageTextContains('Overall Compliance Status');

    // Test 2: Initial compliance status display (no submodules)
    // With no submodules enabled, should show non-compliant status.
    $this->assertSession()->pageTextContains('OAuth 2.1 Core Requirements');
    $this->assertSession()->pageTextContains('Critical Compliance Errors');
    $this->assertSession()->pageTextContains('PKCE module must be installed');
    $this->assertSession()->pageTextContains('0%');

    // Test 3: Dashboard updates with partial compliance (PKCE only)
    // Enable PKCE submodule.
    $this->container->get('module_installer')->install(['simple_oauth_pkce']);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->pageTextContains('PKCE Module Enabled');
    $this->assertSession()->responseContains('%');

    // Test 4: Dashboard updates with full submodule installation
    // Enable all submodules.
    $this->container->get('module_installer')->install([
      'simple_oauth_native_apps',
      'simple_oauth_server_metadata',
    ]);
    $this->drupalGet('/admin/config/people/simple_oauth/oauth-21');
    $this->assertSession()->pageTextContains('Native Apps Security Module');
    $this->assertSession()->pageTextContains('Server Metadata Module Enabled');
    $this->assertSession()->pageTextContains('Server Metadata (RFC 8414)');

    // Test 5: Dashboard displays all required sections
    // Check that all main sections are present on the dashboard.
    $this->assertSession()->pageTextContains('Overall Compliance Status');
    $this->assertSession()->pageTextContains('OAuth 2.1 Core Requirements');
    $this->assertSession()->pageTextContains('Server Metadata (RFC 8414)');
    $this->assertSession()->pageTextContains('Security Best Practices');
  }

}
