<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_device_flow\Unit\Plugin\Oauth2Grant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Repositories\OptionalRefreshTokenRepositoryInterface;
use Drupal\simple_oauth_device_flow\Plugin\Oauth2Grant\DeviceCodeGrant;
use Drupal\simple_oauth_device_flow\Service\DeviceFlowSettingsService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Grant\DeviceCodeGrant as LeagueDeviceCodeGrant;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Unit tests for DeviceCodeGrant plugin.
 *
 * @coversDefaultClass \Drupal\simple_oauth_device_flow\Plugin\Oauth2Grant\DeviceCodeGrant
 */
#[Group('simple_oauth_device_flow')]
final class DeviceCodeGrantTest extends UnitTestCase {

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

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturn('https://example.org/base/device');
    $verification_url = Url::fromRoute('simple_oauth_device_flow.device_verification_form');
    $verification_url->setUrlGenerator($url_generator);
    $path_validator = $this->createMock(PathValidatorInterface::class);
    $path_validator->method('getUrlIfValid')->willReturn($verification_url);
    $this->deviceCodeGrant = new DeviceCodeGrant(
      [],
      'device_code',
      ['label' => 'Device Code'],
      $this->deviceCodeRepository->reveal(),
      $this->refreshTokenRepository->reveal(),
      new DeviceFlowSettingsService($this->configFactory->reveal(), $path_validator)
    );
  }

  /**
   * Tests the getGrantType method with default configuration.
   *
   * @covers ::getGrantType
   */
  public function testGetGrantTypeWithDefaults(): void {
    // Setup default configuration.
    $this->config->get('device_code_lifetime')->willReturn(NULL);
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
    $this->config->get('device_code_lifetime')->willReturn(1800);
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
    $this->config->get('device_code_lifetime')->willReturn(3600);
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
    foreach ([
      ['device_code'],
      ['urn:ietf:params:oauth:grant-type:device_code'],
      ['device_code', 'urn:ietf:params:oauth:grant-type:device_code'],
      ['authorization_code'],
      [],
    ] as $grants) {
      $field = $this->prophesize(FieldItemListInterface::class);
      $field->getValue()->willReturn(array_map(static fn(string $grant): array => ['value' => $grant], $grants));
      $this->consumer->get('grant_types')->willReturn($field->reveal());
      $this->assertSame(
        $grants !== [] && $grants !== ['authorization_code'],
        $this->deviceCodeGrant->isClientEligible($this->consumer->reveal()),
      );
    }
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

  /**
   * Tests actual League issuance uses the canonical lifetime and fallback.
   *
   * @param int|null $configured_lifetime
   *   The configured lifetime, or NULL for the default.
   * @param int $expected_lifetime
   *   The expected response and persisted lifetime in seconds.
   *
   * @dataProvider deviceLifetimeProvider
   */
  #[DataProvider('deviceLifetimeProvider')]
  public function testGrantResponseUsesCanonicalLifetime(?int $configured_lifetime, int $expected_lifetime): void {
    // User-code fixtures intentionally use the valid consonant-only alphabet.
    // cspell:ignore BCDF GHJK
    $this->config->get('device_code_lifetime')->willReturn($configured_lifetime);
    $this->config->get('verification_uri')->willReturn(NULL);
    $this->config->get('polling_interval')->willReturn(NULL);
    $grant_types = $this->prophesize(FieldItemListInterface::class);
    $grant_types->getValue()->willReturn([
      ['value' => 'urn:ietf:params:oauth:grant-type:device_code'],
    ]);
    $this->consumer->get('grant_types')->willReturn($grant_types->reveal());
    $this->refreshTokenRepository->disableRefreshToken()->shouldBeCalledOnce();
    $expiry = new \DateTimeImmutable();
    $device = $this->createMock(DeviceCodeEntityInterface::class);
    $device->method('setExpiryDateTime')->willReturnCallback(static function (\DateTimeImmutable $value) use (&$expiry): void {
      $expiry = $value;
    });
    $device->method('getExpiryDateTime')->willReturnCallback(static function () use (&$expiry): \DateTimeImmutable {
      return $expiry;
    });
    $device->method('getIdentifier')->willReturn('opaque-device-code');
    $device->method('getUserCode')->willReturn('BCDF-GHJK');
    $device->expects($this->once())->method('setVerificationUri')->with('https://example.org/base/device');
    $device->method('getVerificationUri')->willReturn('https://example.org/base/device');
    $this->deviceCodeRepository->getNewDeviceCode()->willReturn($device);
    $this->deviceCodeRepository->persistDeviceCode($device)->shouldBeCalledOnce();
    $client = $this->createMock(ClientEntityInterface::class);
    $client->method('getIdentifier')->willReturn('grant-lifetime-client');
    $clients = $this->createMock(ClientRepositoryInterface::class);
    $clients->method('getClientEntity')->with('grant-lifetime-client')->willReturn($client);
    $grant = $this->deviceCodeGrant->getGrantType($this->consumer->reveal());
    $this->assertInstanceOf(LeagueDeviceCodeGrant::class, $grant);
    $this->assertSame('urn:ietf:params:oauth:grant-type:device_code', $grant->getIdentifier());
    $grant->setClientRepository($clients);
    $grant->setScopeRepository($this->createMock(ScopeRepositoryInterface::class));
    $grant->setDefaultScope('');
    $request = (new ServerRequest('POST', '/oauth/device_authorization'))
      ->withParsedBody(['client_id' => 'grant-lifetime-client']);
    $before = time();
    $response = $grant->respondToDeviceAuthorizationRequest($request)
      ->generateHttpResponse(new Response());
    $after = time();
    $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertGreaterThanOrEqual($before + $expected_lifetime, $expiry->getTimestamp());
    $this->assertLessThanOrEqual($after + $expected_lifetime, $expiry->getTimestamp());
    $this->assertGreaterThanOrEqual($expected_lifetime - ($after - $before), $data['expires_in']);
    $this->assertLessThanOrEqual($expected_lifetime, $data['expires_in']);
  }

  /**
   * Provides configured and fallback device lifetimes.
   *
   * @return array<string, array{int|null, int}>
   *   Lifetime inputs and expected expiry deltas.
   */
  public static function deviceLifetimeProvider(): array {
    return [
      'nondefault lifetime' => [143, 143],
      'default lifetime' => [NULL, 1800],
    ];
  }

}
