<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_pkce\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trivial unit test to ensure unit test infrastructure works.
 *
 * @group simple_oauth_21
 */
class TrivialUnitTrivialTest extends TestCase {

  /**
   * Tests that unit tests can run.
   */
  public function testTrivial(): void {
    $this->assertEquals('trivial', strtolower('TRIVIAL'));
  }

}
