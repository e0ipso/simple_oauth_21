<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_21\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Trivial kernel test to ensure kernel test infrastructure works.
 *
 * @group simple_oauth_21
 */
class TrivialKernelTrivialTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['simple_oauth_21'];

  /**
   * Tests that kernel tests can run.
   */
  public function testTrivial(): void {
    $this->assertEquals('trivial', strtolower('TRIVIAL'));
  }

}
