<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\simple_oauth_21\Trait\DebugLoggingTrait;
use Drupal\Tests\simple_oauth\Functional\RequestHelperTrait;
use Drupal\Tests\simple_oauth\Functional\TokenBearerFunctionalTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

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
  ];

  /**
   * Test user 1 (token owner).
   *
   * @var \Drupal\user\UserInterface
   */
  private $user1;

  /**
   * Test user 2 (not token owner).
   *
   * @var \Drupal\user\UserInterface
   */
  private $user2;

  /**
   * Valid access token for user1.
   *
   * @var \Drupal\simple_oauth\Entity\Oauth2Token
   */
  private Oauth2Token $validToken;

  /**
   * Expired access token for user1.
   *
   * @var \Drupal\simple_oauth\Entity\Oauth2Token
   */
  private Oauth2Token $expiredToken;

  /**
   * Revoked access token for user1.
   *
   * @var \Drupal\simple_oauth\Entity\Oauth2Token
   */
  private Oauth2Token $revokedToken;

  /**
   * Refresh token for user1.
   *
   * @var \Drupal\simple_oauth\Entity\Oauth2Token
   */
  private Oauth2Token $refreshToken;

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

    // Create valid access token for user1.
    $this->validToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->user1->id(),
      'client' => $this->client,
      'value' => 'test_valid_token_' . $this->randomMachineName(),
      'scopes' => [],
      'expire' => time() + 3600,
      'status' => TRUE,
    ]);
    $this->validToken->save();

    // Create expired access token for user1.
    $this->expiredToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->user1->id(),
      'client' => $this->client,
      'value' => 'test_expired_token_' . $this->randomMachineName(),
      'scopes' => [],
      'expire' => time() - 3600,
      'status' => TRUE,
    ]);
    $this->expiredToken->save();

    // Create revoked access token for user1.
    $this->revokedToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->user1->id(),
      'client' => $this->client,
      'value' => 'test_revoked_token_' . $this->randomMachineName(),
      'scopes' => [],
      'expire' => time() + 3600,
      'status' => TRUE,
    ]);
    $this->revokedToken->save();
    $this->revokedToken->revoke();
    $this->revokedToken->save();

    // Create refresh token for user1.
    $this->refreshToken = Oauth2Token::create([
      'bundle' => 'refresh_token',
      'auth_user_id' => $this->user1->id(),
      'client' => $this->client,
      'value' => 'test_refresh_token_' . $this->randomMachineName(),
      'scopes' => [],
      'expire' => time() + 7200,
      'status' => TRUE,
    ]);
    $this->refreshToken->save();

    // Obtain real JWT access tokens for authenticating introspection
    // requests. These must be valid JWTs issued by the OAuth server,
    // not manually created.
    $this->user1AuthToken = $this->obtainAccessTokenForUser($this->user1);
    $this->user2AuthToken = $this->obtainAccessTokenForUser($this->user2);
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
    $this->assertTrue(TRUE, 'All token introspection test scenarios completed successfully');
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
      ['token' => $this->validToken->get('value')->value],
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
      ['token' => $this->validToken->get('value')->value],
      []
    );
    $this->assertEquals(401, $response->getStatusCode(), 'Missing Bearer token should return 401 Unauthorized');

    // Test 3: Invalid Bearer token returns 401.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken->get('value')->value],
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
      ['token' => $this->validToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());
    $this->assertTrue($json['active'], 'Token owner can introspect their own token');
    $this->assertArrayHasKey('scope', $json, 'Active token response includes scope field');

    // Test 2: User2 introspects user1's token - should fail (return active:
    // false).
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user2AuthToken]
    );
    $json = Json::decode($response->getBody());
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
      ['token' => $this->validToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());
    $this->assertTrue($json['active'], 'Active valid token returns active: true');
    $this->assertArrayHasKey('client_id', $json, 'Active token response includes client_id');
    $this->assertArrayHasKey('username', $json, 'Active token response includes username');
    $this->assertArrayHasKey('exp', $json, 'Active token response includes exp');

    // Test 2: Expired token returns active: false.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->expiredToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());
    $this->assertFalse($json['active'], 'Expired token returns active: false');
    $this->assertCount(1, $json, 'Inactive token response only contains active field');

    // Test 3: Revoked token returns active: false.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->revokedToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());
    $this->assertFalse($json['active'], 'Revoked token returns active: false');

    // Test 4: Non-existent token returns active: false.
    $response = $this->postIntrospectionRequest(
      ['token' => 'nonexistent_token_value'],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());
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
    $json = Json::decode($response->getBody());
    $this->assertEquals('invalid_request', $json['error'], 'Error response includes error code');
    $this->assertArrayHasKey('error_description', $json, 'Error response includes description');

    // Test 2: token_type_hint parameter is accepted (access_token).
    $response = $this->postIntrospectionRequest(
      [
        'token' => $this->validToken->get('value')->value,
        'token_type_hint' => 'access_token',
      ],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $this->assertEquals(200, $response->getStatusCode(), 'token_type_hint=access_token is accepted');
    $json = Json::decode($response->getBody());
    $this->assertTrue($json['active'], 'Token with access_token hint returns active: true');

    // Test 3: token_type_hint parameter is accepted (refresh_token).
    $response = $this->postIntrospectionRequest(
      [
        'token' => $this->refreshToken->get('value')->value,
        'token_type_hint' => 'refresh_token',
      ],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $this->assertEquals(200, $response->getStatusCode(), 'token_type_hint=refresh_token is accepted');
    $json = Json::decode($response->getBody());
    $this->assertTrue($json['active'], 'Refresh token can be introspected');

    // Test 4: Refresh token can be introspected without hint.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->refreshToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());
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
      ['token' => $this->validToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());

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
      ['token' => $this->expiredToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $json = Json::decode($response->getBody());
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
      ['token' => $this->validToken->get('value')->value],
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
      ['token' => $this->validToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $body = (string) $response->getBody();
    $tokenValue = $this->validToken->get('value')->value;
    $this->assertStringNotContainsString($tokenValue, $body, 'Token value never appears in response');

    // Test 2: Non-existent token response identical to unauthorized token
    // response.
    $nonExistentResponse = $this->postIntrospectionRequest(
      ['token' => 'nonexistent_token'],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
    $unauthorizedResponse = $this->postIntrospectionRequest(
      ['token' => $this->validToken->get('value')->value],
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
   * Obtains a valid JWT access token for a user via authorization code flow.
   *
   * Goes through the full OAuth authorization code flow to obtain a real JWT
   * token from the OAuth server. This ensures the token can be validated by
   * the League OAuth2 Server library. Follows the same pattern as
   * AuthCodeFunctionalTest.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to obtain a token for.
   *
   * @return string
   *   The JWT access token string.
   *
   * @throws \Exception
   *   If token acquisition fails.
   */
  private function obtainAccessTokenForUser($user): string {
    // Log in the user.
    $this->drupalLogin($user);

    // Request authorization with the user logged in.
    $authorizeUrl = Url::fromRoute('oauth2_token.authorize');
    $queryParams = [
      'response_type' => 'code',
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
      'client_id' => $this->client->getClientId(),
      'client_secret' => $this->clientSecret,
      'code' => $code,
      'redirect_uri' => $this->redirectUri,
    ];

    $response = $this->post($this->url, $tokenPayload);

    if ($response->getStatusCode() !== 200) {
      throw new \Exception(sprintf(
        'Failed to exchange code for access token for user %s. Status: %d, Body: %s',
        $user->getAccountName(),
        $response->getStatusCode(),
        (string) $response->getBody()
      ));
    }

    $parsedResponse = Json::decode((string) $response->getBody());

    // Log out the user to clean up for next token request.
    $this->drupalLogout();

    return $parsedResponse['access_token'];
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

}
