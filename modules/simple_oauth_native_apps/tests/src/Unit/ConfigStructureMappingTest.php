<?php

namespace Drupal\Tests\simple_oauth_native_apps\Unit;

use Drupal\simple_oauth_native_apps\Service\ConfigStructureMapper;
use Drupal\Tests\UnitTestCase;

/**
 * Tests config structure mapping to prevent validation mismatches.
 *
 * @group simple_oauth_native_apps
 * @coversDefaultClass \Drupal\simple_oauth_native_apps\Service\ConfigStructureMapper
 */
class ConfigStructureMappingTest extends UnitTestCase {

  /**
   * The config structure mapper service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\ConfigStructureMapper
   */
  protected ConfigStructureMapper $mapper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mapper = new ConfigStructureMapper();
  }

  /**
   * Tests mapping from form structure to validator structure.
   *
   * @covers ::mapFormToValidatorStructure
   */
  public function testMapFormToValidatorStructure(): void {
    $form_config = [
      'webview_detection' => 'block',
      'allow_custom_uri_schemes' => TRUE,
      'allow_loopback_redirects' => FALSE,
      'enhanced_pkce_for_native' => TRUE,
      'webview_whitelist' => ['TrustedApp/1.0'],
      'webview_patterns' => ['CustomPattern.*'],
      'require_exact_redirect_match' => TRUE,
      'logging_level' => 'warning',
    ];

    $expected_validator_config = [
      'webview' => [
        'detection' => 'block',
      ],
      'allow' => [
        'custom_uri_schemes' => TRUE,
        'loopback_redirects' => FALSE,
      ],
      'enhanced_pkce_for_native' => TRUE,
      'webview_whitelist' => ['TrustedApp/1.0'],
      'webview_patterns' => ['CustomPattern.*'],
      'require_exact_redirect_match' => TRUE,
      'logging_level' => 'warning',
    ];

    $actual = $this->mapper->mapFormToValidatorStructure($form_config);
    $this->assertEquals($expected_validator_config, $actual);
  }

  /**
   * Tests mapping from validator structure to form structure.
   *
   * @covers ::mapValidatorToFormStructure
   */
  public function testMapValidatorToFormStructure(): void {
    $validator_config = [
      'webview' => [
        'detection' => 'warn',
      ],
      'allow' => [
        'custom_uri_schemes' => FALSE,
        'loopback_redirects' => TRUE,
      ],
      'enhanced_pkce_for_native' => FALSE,
      'webview_whitelist' => ['TrustedApp/2.0'],
      'webview_patterns' => ['AnotherPattern.*'],
      'require_exact_redirect_match' => FALSE,
      'logging_level' => 'debug',
    ];

    $expected_form_config = [
      'webview_detection' => 'warn',
      'allow_custom_uri_schemes' => FALSE,
      'allow_loopback_redirects' => TRUE,
      'enhanced_pkce_for_native' => FALSE,
      'webview_whitelist' => ['TrustedApp/2.0'],
      'webview_patterns' => ['AnotherPattern.*'],
      'require_exact_redirect_match' => FALSE,
      'logging_level' => 'debug',
    ];

    $actual = $this->mapper->mapValidatorToFormStructure($validator_config);
    $this->assertEquals($expected_form_config, $actual);
  }

  /**
   * Tests partial config mapping handles missing keys gracefully.
   *
   * @covers ::mapFormToValidatorStructure
   */
  public function testPartialFormToValidatorMapping(): void {
    $partial_form_config = [
      'webview_detection' => 'off',
      'allow_custom_uri_schemes' => TRUE,
      // Missing other keys should be ignored.
    ];

    $expected_validator_config = [
      'webview' => [
        'detection' => 'off',
      ],
      'allow' => [
        'custom_uri_schemes' => TRUE,
      ],
    ];

    $actual = $this->mapper->mapFormToValidatorStructure($partial_form_config);
    $this->assertEquals($expected_validator_config, $actual);
  }

  /**
   * Tests empty config mapping.
   *
   * @covers ::mapFormToValidatorStructure
   */
  public function testEmptyFormConfigMapping(): void {
    $empty_config = [];
    $actual = $this->mapper->mapFormToValidatorStructure($empty_config);
    $this->assertEmpty($actual);
  }

  /**
   * Tests round-trip conversion preserves data.
   *
   * This test ensures that converting form->validator->form doesn't lose data.
   */
  public function testRoundTripConversion(): void {
    $original_form_config = [
      'webview_detection' => 'block',
      'allow_custom_uri_schemes' => TRUE,
      'allow_loopback_redirects' => FALSE,
      'enhanced_pkce_for_native' => TRUE,
    ];

    // Form -> Validator -> Form should preserve data.
    $validator_config = $this->mapper->mapFormToValidatorStructure($original_form_config);
    $final_form_config = $this->mapper->mapValidatorToFormStructure($validator_config);

    $this->assertEquals($original_form_config, $final_form_config);
  }

  /**
   * Tests structure documentation is comprehensive.
   *
   * @covers ::getValidatorStructureDocumentation
   * @covers ::getFormStructureDocumentation
   */
  public function testStructureDocumentation(): void {
    $validator_docs = $this->mapper->getValidatorStructureDocumentation();
    $form_docs = $this->mapper->getFormStructureDocumentation();

    // Ensure documentation exists and has expected keys.
    $this->assertArrayHasKey('description', $validator_docs);
    $this->assertArrayHasKey('structure', $validator_docs);
    $this->assertArrayHasKey('description', $form_docs);
    $this->assertArrayHasKey('structure', $form_docs);

    // Ensure critical config keys are documented.
    $validator_structure = $validator_docs['structure'];
    $form_structure = $form_docs['structure'];

    // Validator structure should be nested.
    $this->assertArrayHasKey('webview', $validator_structure);
    $this->assertArrayHasKey('allow', $validator_structure);

    // Form structure should be flat.
    $this->assertArrayHasKey('webview_detection', $form_structure);
    $this->assertArrayHasKey('allow_custom_uri_schemes', $form_structure);
    $this->assertArrayHasKey('allow_loopback_redirects', $form_structure);
  }

}
