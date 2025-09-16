<?php

namespace Drupal\simple_oauth_native_apps\Plugin\Oauth2Grant;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService;
use Drupal\simple_oauth_native_apps\Service\NativeClientDetector;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The native app enhanced authorization code grant plugin.
 *
 * Implements RFC 8252 enhanced authorization code grant with mandatory PKCE
 * validation for native OAuth clients. This grant enforces stricter security
 * requirements including S256 challenge method and entropy validation.
 *
 * @Oauth2Grant(
 *   id = "native_app_authorization_code",
 *   label = @Translation("Native App Authorization Code"),
 * )
 */
class NativeAppAuthorizationCode extends Oauth2GrantBase implements ContainerFactoryPluginInterface {

  /**
   * The authorization code repository.
   *
   * @var \League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface
   */
  protected AuthCodeRepositoryInterface $authCodeRepository;

  /**
   * The refresh token repository.
   *
   * @var \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface
   */
  protected RefreshTokenRepositoryInterface $refreshTokenRepository;

  /**
   * The PKCE enhancement service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService
   */
  protected PKCEEnhancementService $pkceEnhancementService;

  /**
   * The native client detector service.
   *
   * @var \Drupal\simple_oauth_native_apps\Service\NativeClientDetector
   */
  protected NativeClientDetector $nativeClientDetector;

  /**
   * Class constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface $auth_code_repository
   *   The authorization code repository.
   * @param \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface $refresh_token_repository
   *   The refresh token repository.
   * @param \Drupal\simple_oauth_native_apps\Service\PKCEEnhancementService $pkce_enhancement_service
   *   The PKCE enhancement service.
   * @param \Drupal\simple_oauth_native_apps\Service\NativeClientDetector $native_client_detector
   *   The native client detector service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AuthCodeRepositoryInterface $auth_code_repository,
    RefreshTokenRepositoryInterface $refresh_token_repository,
    PKCEEnhancementService $pkce_enhancement_service,
    NativeClientDetector $native_client_detector,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->authCodeRepository = $auth_code_repository;
    $this->refreshTokenRepository = $refresh_token_repository;
    $this->pkceEnhancementService = $pkce_enhancement_service;
    $this->nativeClientDetector = $native_client_detector;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_oauth.repositories.auth_code'),
      $container->get('simple_oauth.repositories.refresh_token'),
      $container->get('simple_oauth_native_apps.pkce_enhancement'),
      $container->get('simple_oauth_native_apps.native_client_detector')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getGrantType(Consumer $client): GrantTypeInterface {
    // Validate this is a native client that requires enhanced PKCE.
    if (!$this->nativeClientDetector->requiresEnhancedPkce($client)) {
      throw new \InvalidArgumentException('Native app authorization code grant is only available for native clients requiring enhanced PKCE.');
    }

    $auth_code_ttl = new \DateInterval(
      sprintf('PT%dS', $client->get('access_token_expiration')->value)
    );

    $refresh_token_enabled = $this->isRefreshTokenEnabled($client);

    /** @var \Drupal\simple_oauth\Repositories\OptionalRefreshTokenRepositoryInterface $refresh_token_repository */
    $refresh_token_repository = $this->refreshTokenRepository;
    if (!$refresh_token_enabled) {
      $refresh_token_repository->disableRefreshToken();
    }

    $grant_type = new AuthCodeGrant(
      $this->authCodeRepository,
      $refresh_token_repository,
      $auth_code_ttl
    );

    if ($refresh_token_enabled) {
      $refresh_token = !$client->get('refresh_token_expiration')->isEmpty ? $client->get('refresh_token_expiration')->value : 1209600;
      $refresh_token_ttl = new \DateInterval(
        sprintf('PT%dS', $refresh_token)
      );
      $grant_type->setRefreshTokenTTL($refresh_token_ttl);
    }

    // Enhanced PKCE is mandatory for native clients.
    // The grant will always require PKCE for public clients.
    // Additional validation is handled by the PkceValidationSubscriber.
    // Override any client-level PKCE setting - native clients MUST use PKCE.
    $grant_type->enableCodeChallengeForPublicClients();

    return $grant_type;
  }

  /**
   * Checks if refresh token is enabled on the client.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The consumer entity.
   *
   * @return bool
   *   Returns boolean.
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
   * Validates PKCE parameters specifically for native clients.
   *
   * This method provides additional validation beyond the base OAuth2 server
   * implementation, including entropy validation and S256 method enforcement.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   * @param string|null $code_challenge
   *   The code challenge parameter.
   * @param string|null $code_challenge_method
   *   The code challenge method.
   * @param string|null $code_verifier
   *   The code verifier (for token requests).
   *
   * @return array
   *   Validation result with 'valid' boolean and 'errors' array.
   */
  public function validateEnhancedPkce(
    Consumer $client,
    ?string $code_challenge = NULL,
    ?string $code_challenge_method = NULL,
    ?string $code_verifier = NULL,
  ): array {
    return $this->pkceEnhancementService->validatePkceParameters(
      $client,
      $code_challenge,
      $code_challenge_method,
      $code_verifier
    );
  }

  /**
   * Gets the enhanced PKCE requirements for this grant.
   *
   * @return array
   *   Array of PKCE requirements and configuration.
   */
  public function getPkceRequirements(): array {
    return [
      'mandatory' => TRUE,
      'challenge_method' => 'S256',
      'minimum_entropy_bits' => PKCEEnhancementService::MINIMUM_ENTROPY_BITS,
      'timing_attack_protection' => TRUE,
      'enhanced_validation' => TRUE,
    ];
  }

  /**
   * Checks if the client is eligible for this grant type.
   *
   * @param \Drupal\consumers\Entity\Consumer $client
   *   The OAuth client.
   *
   * @return bool
   *   TRUE if the client can use this grant type.
   */
  public function isClientEligible(Consumer $client): bool {
    // Only native clients requiring enhanced PKCE can use this grant.
    return $this->nativeClientDetector->requiresEnhancedPkce($client);
  }

  /**
   * Gets grant-specific security information.
   *
   * @return array
   *   Security information for this grant type.
   */
  public function getSecurityInfo(): array {
    return [
      'rfc_compliance' => ['RFC 6749', 'RFC 7636', 'RFC 8252'],
      'security_features' => [
        'mandatory_pkce',
        's256_challenge_method',
        'entropy_validation',
        'timing_attack_protection',
        'native_client_detection',
      ],
      'threat_mitigation' => [
        'authorization_code_interception',
        'csrf_attacks',
        'replay_attacks',
      ],
    ];
  }

}
