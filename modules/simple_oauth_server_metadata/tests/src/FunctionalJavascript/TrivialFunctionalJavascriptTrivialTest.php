<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Trivial functional javascript test to ensure JS test infrastructure works.
 *
 * @group simple_oauth_21
 */
class TrivialFunctionalJavascriptTrivialTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['simple_oauth_21'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that functional javascript tests can run.
   */
  public function testTrivial(): void {
    $this->assertEquals('trivial', strtolower('TRIVIAL'));
  }

}
