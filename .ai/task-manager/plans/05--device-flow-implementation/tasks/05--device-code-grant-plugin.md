---
id: 5
group: 'grant-implementation'
dependencies: [4]
status: 'pending'
created: '2025-09-26'
skills: ['drupal-backend', 'php']
---

# Implement Device Code Grant Plugin

## Objective

Create the Device Code Grant plugin that extends Oauth2GrantBase and integrates league/oauth2-server's DeviceCodeGrant with Simple OAuth's plugin system.

## Skills Required

- **drupal-backend**: Plugin system, Simple OAuth patterns
- **php**: Grant configuration, dependency injection

## Acceptance Criteria

- [ ] Plugin extends Oauth2GrantBase
- [ ] Proper plugin annotation for discovery
- [ ] Integrates league/oauth2-server DeviceCodeGrant
- [ ] Configures verification URI and intervals
- [ ] Implements getGrantType() method correctly
- [ ] Registers with Simple OAuth grant manager

## Technical Requirements

- Extend Oauth2GrantBase (follow simple_oauth_pkce patterns)
- Use league/oauth2-server DeviceCodeGrant class
- Configure grant with proper repositories and settings
- Plugin annotation for Simple OAuth discovery

## Input Dependencies

- DeviceCode repository from task 4
- Module configuration from task 1

## Output Artifacts

- src/Plugin/Oauth2Grant/DeviceCodeGrant.php
- Plugin integration with Simple OAuth
- Grant configuration and setup

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Plugin structure (study simple_oauth_pkce grant plugins):**

```php
/**
 * @Oauth2Grant(
 *   id = "device_code",
 *   label = @Translation("Device Code"),
 * )
 */
class DeviceCodeGrant extends Oauth2GrantBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DeviceCodeRepositoryInterface $deviceCodeRepository,
    RefreshTokenRepositoryInterface $refreshTokenRepository,
    ConfigFactoryInterface $configFactory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    // Store dependencies
  }

  public static function create(ContainerInterface $container, ...) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_oauth_device_flow.repositories.device_code'),
      $container->get('simple_oauth.repositories.refresh_token'),
      $container->get('config.factory')
    );
  }

  public function getGrantType(Consumer $client): GrantTypeInterface {
    $config = $this->configFactory->get('simple_oauth_device_flow.settings');

    $deviceCodeTTL = new \DateInterval('PT' . $config->get('device_code_lifetime') . 'S');
    $verificationUri = $config->get('verification_uri');
    $interval = $config->get('polling_interval');

    $grant = new \League\OAuth2\Server\Grant\DeviceCodeGrant(
      $this->deviceCodeRepository,
      $this->refreshTokenRepository,
      $deviceCodeTTL,
      $verificationUri,
      $interval
    );

    // Configure refresh token TTL if enabled
    if ($this->isRefreshTokenEnabled($client)) {
      $refreshTokenTTL = new \DateInterval('PT' . $client->get('refresh_token_expiration')->value . 'S');
      $grant->setRefreshTokenTTL($refreshTokenTTL);
    }

    return $grant;
  }
}
```

**Key considerations:**

- Use configuration settings for TTL, URI, interval
- Handle refresh token configuration per client
- Follow Simple OAuth plugin patterns exactly
- Ensure proper service registration and discovery
</details>
