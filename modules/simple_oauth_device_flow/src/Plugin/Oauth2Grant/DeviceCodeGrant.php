<?php

namespace Drupal\simple_oauth_device_flow\Plugin\Oauth2Grant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use League\OAuth2\Server\Grant\DeviceCodeGrant as LeagueDeviceCodeGrant;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OAuth 2.0 Device Authorization Grant plugin.
 *
 * Implements RFC 8628 Device Authorization Grant for devices with limited
 * input capabilities or lack a suitable browser. Provides secure authorization
 * flow using device codes and user codes.
 *
 * @Oauth2Grant(
 *   id = "urn:ietf:params:oauth:grant-type:device_code",
 *   label = @Translation("Device Code"),
 * )
 */
class DeviceCodeGrant extends Oauth2GrantBase implements ContainerFactoryPluginInterface {

  /**
   * The device code repository.
   *
   * @var \League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface
   */
  protected DeviceCodeRepositoryInterface $deviceCodeRepository;

  /**
   * The refresh token repository.
   *
   * @var \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface
   */
  protected RefreshTokenRepositoryInterface $refreshTokenRepository;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a DeviceCodeGrant plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface $device_code_repository
   *   The device code repository.
   * @param \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface $refresh_token_repository
   *   The refresh token repository.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DeviceCodeRepositoryInterface $device_code_repository,
    RefreshTokenRepositoryInterface $refresh_token_repository,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->deviceCodeRepository = $device_code_repository;
    $this->refreshTokenRepository = $refresh_token_repository;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_oauth_device_flow.device_code_repository'),
      $container->get('simple_oauth.repositories.refresh_token'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getGrantType(Consumer $client): GrantTypeInterface {
    $config = $this->configFactory->get('simple_oauth_device_flow.settings');

    // Get device code TTL (default 30 minutes).
    $device_code_lifetime = $config->get('device_code_expiration') ?? 1800;
    $device_code_ttl = new \DateInterval(sprintf('PT%dS', $device_code_lifetime));

    // Get verification URI (default path).
    $verification_uri = $config->get('verification_uri') ?? '/oauth/device/verify';

    // Get polling interval (default 5 seconds).
    $polling_interval = $config->get('polling_interval') ?? 5;

    $refresh_token_enabled = $this->isRefreshTokenEnabled($client);

    /** @var \Drupal\simple_oauth\Repositories\OptionalRefreshTokenRepositoryInterface $refresh_token_repository */
    $refresh_token_repository = $this->refreshTokenRepository;
    if (!$refresh_token_enabled) {
      $refresh_token_repository->disableRefreshToken();
    }

    // Create the Device Code Grant with proper configuration.
    $grant_type = new LeagueDeviceCodeGrant(
      $this->deviceCodeRepository,
      $refresh_token_repository,
      $device_code_ttl,
      $verification_uri,
      $polling_interval
    );

    // Configure refresh token TTL if enabled.
    if ($refresh_token_enabled) {
      $refresh_token_expiration = !$client->get('refresh_token_expiration')->isEmpty()
        ? $client->get('refresh_token_expiration')->value
      // 14 days default
        : 1209600;

      $refresh_token_ttl = new \DateInterval(sprintf('PT%dS', $refresh_token_expiration));
      $grant_type->setRefreshTokenTTL($refresh_token_ttl);
    }

    return $grant_type;
  }

  /**
   * Checks if refresh token is enabled on the client.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return bool
   *   Returns TRUE if refresh tokens are enabled for this client.
   */
  protected function isRefreshTokenEnabled(Consumer $client): bool {
    foreach ($client->get('grant_types')->getValue() as $grant_type) {
      if ($grant_type['value'] === 'refresh_token') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets the device flow configuration.
   *
   * @return array
   *   Array of device flow configuration values.
   */
  public function getDeviceFlowConfig(): array {
    $config = $this->configFactory->get('simple_oauth_device_flow.settings');

    return [
      'device_code_lifetime' => $config->get('device_code_expiration') ?? 1800,
      'verification_uri' => $config->get('verification_uri') ?? '/oauth/device/verify',
      'polling_interval' => $config->get('polling_interval') ?? 5,
      'user_code_length' => $config->get('user_code_length') ?? 8,
      'user_code_format' => $config->get('user_code_format') ?? 'XXXX-XXXX',
    ];
  }

  /**
   * Validates if the client is eligible for device code grant.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   *
   * @return bool
   *   TRUE if the client can use device code grant.
   */
  public function isClientEligible(Consumer $client): bool {
    // Check if device_code grant is enabled for this client.
    foreach ($client->get('grant_types')->getValue() as $grant_type) {
      if ($grant_type['value'] === 'device_code') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets security information for this grant type.
   *
   * @return array
   *   Security information for device code grant.
   */
  public function getSecurityInfo(): array {
    return [
      'rfc_compliance' => ['RFC 6749', 'RFC 8628'],
      'security_features' => [
        'device_verification',
        'user_authorization',
        'time_limited_codes',
        'polling_protection',
      ],
      'use_cases' => [
        'smart_tvs',
        'iot_devices',
        'cli_applications',
        'limited_input_devices',
      ],
      'threat_mitigation' => [
        'device_code_expiration',
        'rate_limiting',
        'user_verification',
      ],
    ];
  }

}
