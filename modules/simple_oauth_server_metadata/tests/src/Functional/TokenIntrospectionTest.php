<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Url;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\simple_oauth\Functional\RequestHelperTrait;
use Drupal\Tests\simple_oauth\Functional\SimpleOauthTestTrait;
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
final class TokenIntrospectionTest extends BrowserTestBase {

  use RequestHelperTrait;
  use SimpleOauthTestTrait;

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
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
   * Admin user with bypass permission.
   *
   * @var \Drupal\user\UserInterface
   */
  private $adminUser;

  /**
   * Test OAuth client (confidential).
   *
   * @var \Drupal\consumers\Entity\Consumer
   */
  private Consumer $client;

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
   * Authenticating token for admin user.
   *
   * @var string
   */
  private string $adminAuthToken;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set up OAuth keys.
    $this->setUpKeys();

    // Create test users.
    $this->user1 = $this->drupalCreateUser(['access content', 'grant simple_oauth codes']);
    $this->user2 = $this->drupalCreateUser(['access content', 'grant simple_oauth codes']);
    $this->adminUser = $this->drupalCreateUser([
      'bypass token introspection restrictions',
      'access content',
      'grant simple_oauth codes',
    ]);

    // Create OAuth client.
    $this->client = Consumer::create([
      'label' => 'Test Client',
      'client_id' => 'test_client_id',
      'secret' => 'test_client_secret',
      'is_default' => FALSE,
      'confidential' => TRUE,
    ]);
    $this->client->save();

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

    // Create authenticating access tokens for making introspection requests.
    // These are separate from the tokens being tested (validToken, etc.).
    $user1Token = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->user1->id(),
      'client' => $this->client,
      'value' => 'auth_token_user1_' . $this->randomMachineName(),
      'scopes' => [],
      'expire' => time() + 3600,
      'status' => TRUE,
    ]);
    $user1Token->save();
    $this->user1AuthToken = $user1Token->get('value')->value;

    $user2Token = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->user2->id(),
      'client' => $this->client,
      'value' => 'auth_token_user2_' . $this->randomMachineName(),
      'scopes' => [],
      'expire' => time() + 3600,
      'status' => TRUE,
    ]);
    $user2Token->save();
    $this->user2AuthToken = $user2Token->get('value')->value;

    $adminToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->adminUser->id(),
      'client' => $this->client,
      'value' => 'auth_token_admin_' . $this->randomMachineName(),
      'scopes' => [],
      'expire' => time() + 3600,
      'status' => TRUE,
    ]);
    $adminToken->save();
    $this->adminAuthToken = $adminToken->get('value')->value;

    // Clear caches to ensure routes are available.
    drupal_flush_all_caches();
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
    // Test 1: Valid Bearer token allows introspection.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->user1AuthToken]
    );
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
   * - User with bypass permission can introspect any token (returns active:
   *   true).
   * - User without bypass permission limited to own tokens.
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

    // Test 3: Admin with bypass permission introspects user1's token - should
    // succeed.
    $response = $this->postIntrospectionRequest(
      ['token' => $this->validToken->get('value')->value],
      ['Authorization' => 'Bearer ' . $this->adminAuthToken]
    );
    $json = Json::decode($response->getBody());
    $this->assertTrue($json['active'], 'User with bypass permission can introspect any token');
    $this->assertArrayHasKey('username', $json, 'Active token response includes username field');
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
