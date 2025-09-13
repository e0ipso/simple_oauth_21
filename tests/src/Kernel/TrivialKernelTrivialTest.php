<?php

declare(strict_types=1);

namespace Drupal\Tests\gh_contrib_template\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Trivial kernel test to ensure kernel test infrastructure works.
 *
 * @group gh_contrib_template
 */
class TrivialKernelTrivialTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['gh_contrib_template'];

  /**
   * Tests that kernel tests can run.
   */
  public function testTrivial(): void {
    $this->assertEquals('trivial', strtolower('TRIVIAL'));
  }

}
