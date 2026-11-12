<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Drupal\simple_oauth_21\Trait\DebugLoggingTrait;
use Drupal\Tests\simple_oauth\Functional\RequestHelperTrait;
use Drupal\Tests\simple_oauth\Functional\TokenBearerFunctionalTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Functional tests for RFC 7662 Token Introspection endpoint.
 *
 * Tests comprehensive token introspection functionality including
 * authentication, authorization, token validation, request parameters,
 * response formats, integration points, and security constraints.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7662
 */
#[Group('simple_oauth_server_metadata')]
final class TokenIntrospectionTest extends TokenBearerFunctionalTestBase {

  use DebugLoggingTrait;
  use RequestHelperTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'consumers',
    'simple_oauth',
    'simple_oauth_test',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'simple_oauth_pkce',
    'simple_oauth_client_registration',
  ];

  /**
   * Test user 1 (token owner).
   *
   * @var \Drupal\user\UserInterface
   */
  private UserInterface $user1;

  /**
   * Test user 2 (not token owner).
   *
   * @var \Drupal\user\UserInterface
   */
  private UserInterface $user2;

  /**
   * Valid access token for user1.
   *
   * @var string
   */
  private string $validToken;

  /**
   * Expired access token for user1.
   *
   * @var string
   */
  private string $expiredToken;

  /**
   * Revoked access token for user1.
   *
   * @var string
   */
  private string $revokedToken;

  /**
   * Refresh token for user1.
   *
   * @var string
   */
  private string $refreshToken;

  /**
   * Authenticating token for user1.
   *
   * @var string
   */
  private string $user1AuthToken;

  /**
   * Authenticating token for user2.
   *
   * @var string
   */
  private string $user2AuthToken;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Grant permissions to authenticated role (applies to all
    // authenticated users).
    $this->grantPermissions(Role::load(RoleInterface::AUTHENTICATED_ID), [
      'grant simple_oauth codes',
      'access content',
    ]);

    // Create additional test users.
    // parent::setUp() already creates $this->user.
    $this->user1 = $this->user;
    $this->user2 = $this->drupalCreateUser();

    // Use issued representations for both target and authenticating tokens.
    $tokens = $this->obtainTokensForUser($this->user1);
    $this->validToken = $tokens['access_token'];
    $this->refreshToken = $tokens['refresh_token'];
    $this->expiredToken = $this->obtainTokensForUser($this->user1)['access_token'];
    $this->revokedToken = $this->obtainTokensForUser($this->user1)['access_token'];

    // Change persisted state without replacing the issued JWT representations.
    $factory = $this->container->get('simple_oauth.server.resource_server.factory');
    $http_factory = $this->container->get('psr7.http_message_factory');
    $storage = $this->container->get('entity_type.manager')->getStorage('oauth2_token');
    foreach ([$this->expiredToken, $this->revokedToken] as $value) {
      $request = Request::create('/');
      $request->headers->set('Authorization', 'Bearer ' . $value);
      $validated = $factory->get()->validateAuthenticatedRequest($http_factory->createRequest($request));
      $matches = $storage->loadByProperties([
        'value' => $validated->getAttribute('oauth_access_token_id'),
      ]);
      $token = reset($matches);
      if (!$token instanceof Oauth2TokenInterface) {
        throw new \RuntimeException('The issued access token was not persisted.');
      }
      if ($value === $this->expiredToken) {
        $token->set('expire', time() - 3600);
      }
      else {
        $token->revoke();
      }
      $token->save();
    }

    $this->user1AuthToken = $this->obtainTokensForUser($this->user1)['access_token'];
    $this->user2AuthToken = $this->obtainTokensForUser($this->user2)['access_token'];
  }

  /**
   * Test token introspection endpoint RFC 7662 compliance.
   *
   * This comprehensive test validates all RFC 7662 behaviors, security
   * constraints, and integration points in a single test method for optimal
   * performance.
   */
  public function testTokenIntrospectionEndpoint(): void {
    // Phase 1: Authentication Tests.
    $this->doAuthenticationTests();

    // Phase 2: Authorization Tests.
    $this->doAuthorizationTests();

    // Phase 3: Token Validation Tests.
    $this->doTokenValidationTests();

    // Phase 4: Request Parameter Tests.
    $this->doRequestParameterTests();

    // Phase 5: Response Format Tests.
    $this->doResponseFormatTests();

    // Phase 6: Integration Tests.
    $this->doIntegrationTests();

    // Phase 7: Security Tests.
    $this->doSecurityTests();
    $this->doRevocationLifecycle();
    $this->doGlobalPkceEnforcement();
  }

  /**
   * Tests authentication requirements for introspection endpoint.
   *
   * Validates that:
   * - Valid Bearer token allows introspection (200 OK).
   * - Missing Bearer token returns 401 Unauthorized.
   * - Invalid Bearer token returns 401 Unauthorized.
   */
  private function doAuthenticationTests(): void {
    // First verify the token works with GET (which we know works in
    // simple_oauth).
    $this->assertAccessTokenOnResource($this->user1AuthToken);

    // Test 1: Valid Bearer token allows introspection.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    if ($response->getStatusCode() !== 200) {
      // Debug output for troubleshooting.
      $message = sprintf(
        'Expected 200 but got %d. Response body: %s. Auth token first 50 chars: %s',
        $response->getStatusCode(),
        (string) $response->getBody(),
        substr($this->user1AuthToken, 0, 50)
      );
      $this->logDebug($message);
      $this->fail($message);
    }
    $this->assertEquals(200, $response->getStatusCode(), 'Valid Bearer token should allow introspection');

    // Test 2: Missing Bearer token returns 401.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      []
    );
    $this->assertEquals(401, $response->getStatusCode(), 'Missing Bearer token should return 401 Unauthorized');

    // Test 3: Invalid Bearer token returns 401.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer invalid_token_value']
    );
    $this->assertEquals(401, $response->getStatusCode(), 'Invalid Bearer token should return 401 Unauthorized');
  }

  /**
   * Tests authorization checks for token introspection.
   *
   * Validates that:
   * - Token owner can introspect their own token (returns active: true).
   * - Token owner cannot introspect other users' tokens (returns active:
   *   false).
   */
  private function doAuthorizationTests(): void {
    // Test 1: User1 introspects their own token - should succeed.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertTrue($json['active'], 'Token owner can introspect their own token');
    $this->assertArrayHasKey('scope', $json, 'Active token response includes scope field');

    // Test 2: User2 introspects user1's token - should fail (return active:
    // false).
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user2AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertFalse($json['active'], 'User cannot introspect other users\' tokens');
    $this->assertCount(1, $json, 'Unauthorized response only contains active field');
  }

  /**
   * Tests token validation logic.
   *
   * Validates that:
   * - Active valid token returns active: true with metadata.
   * - Expired token returns active: false.
   * - Revoked token returns active: false.
   * - Non-existent token returns active: false.
   */
  private function doTokenValidationTests(): void {
    // Test 1: Active valid token returns active: true with metadata.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertTrue($json['active'], 'Active valid token returns active: true');
    $this->assertArrayHasKey('client_id', $json, 'Active token response includes client_id');
    $this->assertArrayHasKey('username', $json, 'Active token response includes username');
    $this->assertArrayHasKey('exp', $json, 'Active token response includes exp');

    // Test 2: Expired token returns active: false.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->expiredToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertFalse($json['active'], 'Expired token returns active: false');
    $this->assertCount(1, $json, 'Inactive token response only contains active field');

    // Test 3: Revoked token returns active: false.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->revokedToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertFalse($json['active'], 'Revoked token returns active: false');

    // Test 4: Non-existent token returns active: false.
    $response = $this->postIntrospectionRequest(
      ['token' => 'nonexistent_token_value'],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertFalse($json['active'], 'Non-existent token returns active: false');
  }

  /**
   * Tests request parameter handling.
   *
   * Validates that:
   * - Missing token parameter returns 400 Bad Request.
   * - token_type_hint parameter is accepted.
   * - Both access tokens and refresh tokens can be introspected.
   */
  private function doRequestParameterTests(): void {
    // Test 1: Missing token parameter returns 400 Bad Request.
    $response = $this->postIntrospectionRequest(
      [],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $this->assertEquals(400, $response->getStatusCode(), 'Missing token parameter returns 400 Bad Request');
    $json = Json::decode((string) $response->getBody());
    $this->assertEquals('invalid_request', $json['error'], 'Error response includes error code');
    $this->assertArrayHasKey('error_description', $json, 'Error response includes description');

    // Test 2: token_type_hint parameter is accepted (access_token).
    $response = $this->postIntrospectionRequest(
      [
        'token' => $this->validToken,
        'token_type_hint' => 'access_token',
      ],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $this->assertEquals(200, $response->getStatusCode(), 'token_type_hint=access_token is accepted');
    $json = Json::decode((string) $response->getBody());
    $this->assertTrue($json['active'], 'Token with access_token hint returns active: true');

    // Test 3: token_type_hint parameter is accepted (refresh_token).
    $response = $this->postIntrospectionRequest(
      [
        'token' => $this->refreshToken,
        'token_type_hint' => 'refresh_token',
      ],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $this->assertEquals(200, $response->getStatusCode(), 'token_type_hint=refresh_token is accepted');
    $json = Json::decode((string) $response->getBody());
    $this->assertTrue($json['active'], 'Refresh token can be introspected');

    // Test 4: Refresh token can be introspected without hint.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->refreshToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertTrue($json['active'], 'Refresh token can be introspected without hint');
  }

  /**
   * Tests response format compliance with RFC 7662.
   *
   * Validates that:
   * - Required active field is always present.
   * - Optional fields are included for active tokens.
   * - Inactive token response is minimal.
   * - All RFC 7662 fields are properly formatted.
   */
  private function doResponseFormatTests(): void {
    // Test 1: Active token includes all expected fields.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());

    // Verify required field.
    $this->assertArrayHasKey('active', $json, 'Response includes required active field');
    $this->assertIsBool($json['active'], 'active field is boolean');

    // Verify optional fields for active token.
    $this->assertArrayHasKey('scope', $json, 'Active token includes scope field');
    $this->assertArrayHasKey('client_id', $json, 'Active token includes client_id field');
    $this->assertArrayHasKey('username', $json, 'Active token includes username field');
    $this->assertArrayHasKey('token_type', $json, 'Active token includes token_type field');
    $this->assertArrayHasKey('exp', $json, 'Active token includes exp field');
    $this->assertArrayHasKey('iat', $json, 'Active token includes iat field');
    $this->assertArrayHasKey('sub', $json, 'Active token includes sub field');
    $this->assertArrayHasKey('aud', $json, 'Active token includes aud field');
    $this->assertArrayHasKey('iss', $json, 'Active token includes iss field');
    $this->assertArrayHasKey('jti', $json, 'Active token includes jti field');

    // Verify field types.
    $this->assertIsInt($json['exp'], 'exp field is integer timestamp');
    $this->assertIsInt($json['iat'], 'iat field is integer timestamp');
    $this->assertIsString($json['scope'], 'scope field is string');
    $this->assertIsString($json['client_id'], 'client_id field is string');
    $this->assertIsString($json['username'], 'username field is string');
    $this->assertIsString($json['token_type'], 'token_type field is string');
    $this->assertEquals('Bearer', $json['token_type'], 'token_type is Bearer');

    // Test 2: Inactive token response is minimal.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->expiredToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode((string) $response->getBody());
    $this->assertArrayHasKey('active', $json, 'Inactive response includes active field');
    $this->assertFalse($json['active'], 'Inactive response has active: false');
    $this->assertCount(1, $json, 'Inactive response only contains active field');
  }

  /**
   * Tests integration with server metadata and compliance dashboard.
   *
   * Validates that:
   * - Introspection endpoint appears in server metadata.
   * - Compliance dashboard shows RFC 7662 as configured.
   * - Endpoint is accessible at /oauth/introspect.
   */
  private function doIntegrationTests(): void {
    // Test 1: Introspection endpoint appears in server metadata.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);
    $metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertArrayHasKey('introspection_endpoint', $metadata, 'Server metadata includes introspection_endpoint');
    $this->assertStringContainsString('/oauth/introspect', $metadata['introspection_endpoint'], 'Introspection endpoint URL is correct');

    // Test 2: Endpoint is accessible at /oauth/introspect.
    // This is implicitly tested by all other tests, but verify explicitly.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $this->assertEquals(200, $response->getStatusCode(), 'Introspection endpoint is accessible at /oauth/introspect');
  }

  /**
   * Tests security constraints to prevent information disclosure.
   *
   * Validates that:
   * - Token values never appear in responses.
   * - Consistent response for non-existent vs unauthorized tokens.
   * - No information disclosure in error messages.
   */
  private function doSecurityTests(): void {
    // Test 1: Token values never appear in response JSON.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $body = (string) $response->getBody();
    $tokenValue = $this->validToken;
    $this->assertStringNotContainsString($tokenValue, $body, 'Token value never appears in response');

    // Test 2: Non-existent token response identical to unauthorized token
    // response.
    $nonExistentResponse = $this->postIntrospectionRequest(
      ['token' => 'nonexistent_token'],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $unauthorizedResponse = $this->postIntrospectionRequest(
      ['token' => $this->validToken],
      ['Authorization' => 'Bearer ' . $this->user2AuthToken]
    );
    $this->assertEquals(
      (string) $nonExistentResponse->getBody(),
      (string) $unauthorizedResponse->getBody(),
      'Non-existent and unauthorized token responses are identical'
    );

    // Test 3: Error responses don't contain sensitive information.
    $errorResponse = $this->postIntrospectionRequest(
      [],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $errorBody = (string) $errorResponse->getBody();
    $this->assertStringNotContainsString('auth_user_id', $errorBody, 'Error response does not leak field names');
    $this->assertStringNotContainsString('oauth2_token', $errorBody, 'Error response does not leak entity type');
  }

  /**
   * Obtains issued access and refresh tokens via authorization code flow.
   *
   * Goes through the full OAuth authorization code flow to obtain a real JWT
   * token from the OAuth server. This ensures the token can be validated by
   * the League OAuth2 Server library. Follows the same pattern as
   * AuthCodeFunctionalTest.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to obtain a token for.
   * @param string|null $token_verifier
   *   An alternative token verifier for rejection scenarios.
   * @param bool $allow_failure
   *   Whether to return an expected failed exchange instead of throwing.
   *
   * @return array
   *   The issued access and refresh token response.
   *
   * @throws \Exception
   *   If token acquisition fails.
   */
  private function obtainTokensForUser(UserInterface $user, ?string $token_verifier = NULL, bool $allow_failure = FALSE): array {
    // Remove bearer headers and cookies without revoking existing tokens.
    $this->getSession()->setRequestHeader('Authorization', '');
    $this->getSession()->restart();
    $this->loggedInUser = FALSE;
    $this->drupalLogin($user);

    $verifier = str_repeat('x', 64);

    // Request authorization with the user logged in.
    $authorizeUrl = Url::fromRoute('oauth2_token.authorize');
    $queryParams = [
      'response_type' => 'code',
      'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '='),
      'code_challenge_method' => 'S256',
      'client_id' => $this->client->getClientId(),
      'scope' => $this->scope,
      'redirect_uri' => $this->redirectUri,
    ];

    $this->drupalGet($authorizeUrl->toString(), [
      'query' => $queryParams,
    ]);

    // Check if we're on the authorization form or already redirected.
    $session = $this->getSession();
    $currentUrl = $session->getCurrentUrl();

    // If already redirected (e.g., due to automatic authorization or
    // remember approval), we don't need to submit the form.
    if (strpos($currentUrl, $this->redirectUri) !== FALSE) {
      // Already redirected, skip form submission.
    }
    else {
      // We're on the authorization page, submit the form.
      try {
        $this->submitForm([], 'Allow');
      }
      catch (\Exception $e) {
        // Form submission failed - output page content for debugging.
        throw new \Exception(sprintf(
          'Failed to submit authorization form for user %s: %s. Current URL: %s, Page title: %s',
          $user->getAccountName(),
          $e->getMessage(),
          $currentUrl,
          $session->getPage()->find('css', 'title')?->getText() ?? 'No title'
        ));
      }
    }

    // Extract authorization code from the redirect URL.
    $session = $this->getSession();
    $currentUrl = $session->getCurrentUrl();
    $parsedUrl = parse_url($currentUrl);

    if (!isset($parsedUrl['query'])) {
      throw new \Exception(sprintf(
        'No query string in redirect URL for user %s. Full URL: %s',
        $user->getAccountName(),
        $currentUrl
      ));
    }

    parse_str($parsedUrl['query'], $query);

    // Debug: Check what we actually received.
    if (!isset($query['code'])) {
      // Check if there's an error instead.
      if (isset($query['error'])) {
        throw new \Exception(sprintf(
          'OAuth authorization failed for user %s. Error: %s, Description: %s',
          $user->getAccountName(),
          $query['error'],
          $query['error_description'] ?? 'No description'
        ));
      }

      // Check if we're still on the authorization page (not redirected).
      if (isset($query['response_type']) && $query['response_type'] === 'code') {
        throw new \Exception(sprintf(
          'Still on authorization page for user %s (not redirected). This suggests the form submission did not work or was denied. Full URL: %s. Check the browser output HTML files for details.',
          $user->getAccountName(),
          $currentUrl
        ));
      }

      throw new \Exception(sprintf(
        'Authorization code not found for user %s. Query params: %s. Full URL: %s',
        $user->getAccountName(),
        print_r($query, TRUE),
        $currentUrl
      ));
    }

    $code = $query['code'];

    // Exchange authorization code for access token.
    $tokenPayload = [
      'grant_type' => 'authorization_code',
      'code_verifier' => $token_verifier ?? $verifier,
      'client_id' => $this->client->getClientId(),
      'client_secret' => $this->clientSecret,
      'code' => $code,
      'redirect_uri' => $this->redirectUri,
    ];

    $response = $this->post($this->url, $tokenPayload);

    if ($response->getStatusCode() !== 200 && !$allow_failure) {
      throw new \Exception(sprintf(
        'Failed to exchange code for access token for user %s. Status: %d, Body: %s',
        $user->getAccountName(),
        $response->getStatusCode(),
        (string) $response->getBody()
      ));
    }

    $parsedResponse = Json::decode((string) $response->getBody());

    // Clear browser authentication without revoking the issued tokens.
    $this->getSession()->restart();
    $this->loggedInUser = FALSE;

    return $allow_failure ? $parsedResponse + ['_status' => $response->getStatusCode()] : $parsedResponse;
  }

  /**
   * Helper method to POST to the introspection endpoint.
   *
   * @param array $formData
   *   The form data to POST.
   * @param array $headers
   *   Optional HTTP headers.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response object.
   */
  private function postIntrospectionRequest(array $formData = [], array $headers = []): ResponseInterface {
    $url = Url::fromRoute('simple_oauth_server_metadata.token_introspection');

    $options = [];
    if (!empty($headers)) {
      $options['headers'] = $headers;
    }

    return $this->post($url, $formData, $options);
  }

  /**
   * Tests real issued tokens through ownership checks and token revocation.
   */
  private function doRevocationLifecycle(): void {
    $other = Consumer::create([
      'client_id' => 'revocation-other-client',
      'label' => 'Other revocation client',
      'secret' => 'other-client-secret',
      'grant_types' => ['authorization_code', 'refresh_token'],
    ]);
    $other->save();
    $headers = ['Authorization' => 'Bearer ' . $this->user1AuthToken];
    foreach (['access_token', 'refresh_token'] as $type) {
      $issued = $this->obtainTokensForUser($this->user1);
      $value = $issued[$type];
      $response = $this->postIntrospectionRequest(['token' => $value], $headers);
      $this->assertTrue(Json::decode((string) $response->getBody())['active']);
      $credentials = [
        'client_id' => $this->client->getClientId(),
        'client_secret' => $this->clientSecret,
      ];
      $endpoint = Url::fromRoute('simple_oauth_server_metadata.revoke');
      $foreign = $this->post($endpoint, [
        'token' => $value,
        'client_id' => $other->getClientId(),
        'client_secret' => 'other-client-secret',
      ]);
      $this->assertSame(200, $foreign->getStatusCode());
      $response = $this->postIntrospectionRequest(['token' => $value], $headers);
      $this->assertTrue(Json::decode((string) $response->getBody())['active']);
      $tampered = ($value[0] === 'a' ? 'b' : 'a') . substr($value, 1);
      $resolved = $this->container->get('simple_oauth_server_metadata.token_lookup')->resolveToken($value);
      $this->assertNotNull($resolved);
      $identifier = (string) $resolved['token']->get('value')->value;
      foreach ([$tampered, 'unknown-token', $identifier] as $invalid) {
        $response = $this->postIntrospectionRequest(['token' => $invalid], $headers);
        $this->assertSame(['active' => FALSE], Json::decode((string) $response->getBody()));
        $response = $this->post($endpoint, $credentials + ['token' => $invalid]);
        $this->assertSame(200, $response->getStatusCode());
      }
      $response = $this->post($endpoint, $credentials + ['token' => $value, 'token_type_hint' => $type]);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame('', (string) $response->getBody());
      $response = $this->postIntrospectionRequest(['token' => $value], $headers);
      $this->assertSame(['active' => FALSE], Json::decode((string) $response->getBody()));
      if ($type === 'access_token') {
        $this->assertAccessTokenOnResource($value, 401);
      }
      else {
        $response = $this->post($this->url, $credentials + ['grant_type' => 'refresh_token', 'refresh_token' => $value]);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_grant', Json::decode((string) $response->getBody())['error']);
      }
    }
  }

  /**
   * Tests mandatory PKCE for both client types and real verifier matching.
   */
  private function doGlobalPkceEnforcement(): void {
    $this->drupalLogin($this->user1);
    foreach ([TRUE, FALSE] as $confidential) {
      $this->client->set('confidential', $confidential)->set('pkce', FALSE)->save();
      $this->drupalGet(Url::fromRoute('oauth2_token.authorize'), [
        'query' => [
          'response_type' => 'code',
          'client_id' => $this->client->getClientId(),
          'redirect_uri' => $this->redirectUri,
        ],
      ]);
      $this->assertSession()->statusCodeEquals(400);
      $this->assertSame('invalid_request', Json::decode($this->getSession()->getPage()->getContent())['error']);
      $response = $this->post($this->url, [
        'grant_type' => 'authorization_code',
        'client_id' => $this->client->getClientId(),
        'client_secret' => $this->clientSecret,
        'code' => 'missing-verifier-code',
      ]);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame('invalid_request', Json::decode((string) $response->getBody())['error']);
    }
    $this->client->set('confidential', TRUE)->save();
    $this->getSession()->restart();
    $this->loggedInUser = FALSE;
    foreach (['mandatory', 'optional', 'disabled'] as $enforcement) {
      $this->config('simple_oauth_pkce.settings')->set('enforcement', $enforcement)->save();
      $failed = $this->obtainTokensForUser($this->user1, str_repeat('z', 64), TRUE);
      $this->assertSame(400, $failed['_status']);
      $this->assertSame('invalid_grant', $failed['error']);
    }
  }

}
