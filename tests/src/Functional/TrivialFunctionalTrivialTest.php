<?php

declare(strict_types=1);

namespace Drupal\Tests\gh_contrib_template\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Trivial functional test to ensure functional test infrastructure works.
 *
 * @group gh_contrib_template
 */
class TrivialFunctionalTrivialTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['gh_contrib_template'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that functional tests can run.
   */
  public function testTrivial(): void {
    $this->assertEquals('trivial', strtolower('TRIVIAL'));
  }

}
