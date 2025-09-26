<?php

namespace Drupal\Tests\simple_oauth_device_flow\Unit\Plugin\Oauth2Grant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Repositories\OptionalRefreshTokenRepositoryInterface;
use Drupal\simple_oauth_device_flow\Plugin\Oauth2Grant\DeviceCodeGrant;
use Drupal\Tests\UnitTestCase;
use League\OAuth2\Server\Grant\DeviceCodeGrant as LeagueDeviceCodeGrant;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Unit tests for DeviceCodeGrant plugin.
 *
 * @group simple_oauth_device_flow
 *
 * @coversDefaultClass \Drupal\simple_oauth_device_flow\Plugin\Oauth2Grant\DeviceCodeGrant
 */
class DeviceCodeGrantTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The device code grant plugin.
   *
   * @var \Drupal\simple_oauth_device_flow\Plugin\Oauth2Grant\DeviceCodeGrant
   */
  protected DeviceCodeGrant $deviceCodeGrant;

  /**
   * The device code repository.
   *
   * @var \League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $deviceCodeRepository;

  /**
   * The refresh token repository.
   *
   * @var \Drupal\simple_oauth\Repositories\OptionalRefreshTokenRepositoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $refreshTokenRepository;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $configFactory;

  /**
   * The configuration object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $config;

  /**
   * The consumer entity.
   *
   * @var \Drupal\consumers\Entity\Consumer|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $consumer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->deviceCodeRepository = $this->prophesize(DeviceCodeRepositoryInterface::class);
    $this->refreshTokenRepository = $this->prophesize(OptionalRefreshTokenRepositoryInterface::class);
    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->config = $this->prophesize(ImmutableConfig::class);
    $this->consumer = $this->prophesize(Consumer::class);

    $this->configFactory->get('simple_oauth_device_flow.settings')
      ->willReturn($this->config->reveal());

    $this->deviceCodeGrant = new DeviceCodeGrant(
      [],
      'device_code',
      ['label' => 'Device Code'],
      $this->deviceCodeRepository->reveal(),
      $this->refreshTokenRepository->reveal(),
      $this->configFactory->reveal()
    );
  }

  /**
   * Tests the getGrantType method with default configuration.
   *
   * @covers ::getGrantType
   */
  public function testGetGrantTypeWithDefaults(): void {
    // Setup default configuration.
    $this->config->get('device_code_expiration')->willReturn(NULL);
    $this->config->get('verification_uri')->willReturn(NULL);
    $this->config->get('polling_interval')->willReturn(NULL);

    // Setup consumer without refresh token.
    $grant_types_field = $this->prophesize(FieldItemListInterface::class);
    $grant_types_field->getValue()->willReturn([['value' => 'device_code']]);
    $this->consumer->get('grant_types')->willReturn($grant_types_field->reveal());

    // Mock the refresh token repository to disable refresh tokens.
    $this->refreshTokenRepository->disableRefreshToken()->shouldBeCalled();

    $grant_type = $this->deviceCodeGrant->getGrantType($this->consumer->reveal());

    $this->assertInstanceOf(LeagueDeviceCodeGrant::class, $grant_type);
  }

  /**
   * Tests the getGrantType method with refresh tokens enabled.
   *
   * @covers ::getGrantType
   */
  public function testGetGrantTypeWithRefreshTokens(): void {
    // Setup default configuration.
    $this->config->get('device_code_expiration')->willReturn(1800);
    $this->config->get('verification_uri')->willReturn('/oauth/device/verify');
    $this->config->get('polling_interval')->willReturn(5);

    // Setup consumer with refresh token enabled.
    $grant_types_field = $this->prophesize(FieldItemListInterface::class);
    $grant_types_field->getValue()->willReturn([
      ['value' => 'device_code'],
      ['value' => 'refresh_token'],
    ]);
    $this->consumer->get('grant_types')->willReturn($grant_types_field->reveal());

    // Mock refresh token expiration field.
    $refresh_token_field = $this->prophesize(FieldItemListInterface::class);
    $refresh_token_field->isEmpty = FALSE;
    $refresh_token_field->value = 86400;
    $this->consumer->get('refresh_token_expiration')->willReturn($refresh_token_field->reveal());

    $grant_type = $this->deviceCodeGrant->getGrantType($this->consumer->reveal());

    $this->assertInstanceOf(LeagueDeviceCodeGrant::class, $grant_type);
  }

  /**
   * Tests the isRefreshTokenEnabled method.
   *
   * @covers ::isRefreshTokenEnabled
   */
  public function testIsRefreshTokenEnabled(): void {
    $reflection = new \ReflectionClass($this->deviceCodeGrant);
    $method = $reflection->getMethod('isRefreshTokenEnabled');
    $method->setAccessible(TRUE);

    // Test with refresh token enabled.
    $grant_types_field = $this->prophesize(FieldItemListInterface::class);
    $grant_types_field->getValue()->willReturn([
      ['value' => 'device_code'],
      ['value' => 'refresh_token'],
    ]);
    $this->consumer->get('grant_types')->willReturn($grant_types_field->reveal());

    $result = $method->invokeArgs($this->deviceCodeGrant, [$this->consumer->reveal()]);
    $this->assertTrue($result);

    // Test with refresh token disabled.
    $grant_types_field2 = $this->prophesize(FieldItemListInterface::class);
    $grant_types_field2->getValue()->willReturn([['value' => 'device_code']]);
    $this->consumer->get('grant_types')->willReturn($grant_types_field2->reveal());

    $result = $method->invokeArgs($this->deviceCodeGrant, [$this->consumer->reveal()]);
    $this->assertFalse($result);
  }

  /**
   * Tests the getDeviceFlowConfig method.
   *
   * @covers ::getDeviceFlowConfig
   */
  public function testGetDeviceFlowConfig(): void {
    $this->config->get('device_code_expiration')->willReturn(3600);
    $this->config->get('verification_uri')->willReturn('/custom/verify');
    $this->config->get('polling_interval')->willReturn(10);
    $this->config->get('user_code_length')->willReturn(6);
    $this->config->get('user_code_format')->willReturn('XXX-XXX');

    $config = $this->deviceCodeGrant->getDeviceFlowConfig();

    $expected = [
      'device_code_lifetime' => 3600,
      'verification_uri' => '/custom/verify',
      'polling_interval' => 10,
      'user_code_length' => 6,
      'user_code_format' => 'XXX-XXX',
    ];

    $this->assertEquals($expected, $config);
  }

  /**
   * Tests the isClientEligible method.
   *
   * @covers ::isClientEligible
   */
  public function testIsClientEligible(): void {
    // Test eligible client.
    $grant_types_field = $this->prophesize(FieldItemListInterface::class);
    $grant_types_field->getValue()->willReturn([['value' => 'device_code']]);
    $this->consumer->get('grant_types')->willReturn($grant_types_field->reveal());

    $result = $this->deviceCodeGrant->isClientEligible($this->consumer->reveal());
    $this->assertTrue($result);

    // Test ineligible client.
    $grant_types_field2 = $this->prophesize(FieldItemListInterface::class);
    $grant_types_field2->getValue()->willReturn([['value' => 'authorization_code']]);
    $this->consumer->get('grant_types')->willReturn($grant_types_field2->reveal());

    $result = $this->deviceCodeGrant->isClientEligible($this->consumer->reveal());
    $this->assertFalse($result);
  }

  /**
   * Tests the getSecurityInfo method.
   *
   * @covers ::getSecurityInfo
   */
  public function testGetSecurityInfo(): void {
    $info = $this->deviceCodeGrant->getSecurityInfo();

    $this->assertIsArray($info);
    $this->assertArrayHasKey('rfc_compliance', $info);
    $this->assertArrayHasKey('security_features', $info);
    $this->assertArrayHasKey('use_cases', $info);
    $this->assertArrayHasKey('threat_mitigation', $info);

    $this->assertContains('RFC 8628', $info['rfc_compliance']);
  }

}
