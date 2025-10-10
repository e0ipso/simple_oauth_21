<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\consumers\Entity\Consumer;
use Drupal\simple_oauth\Entity\Oauth2Token;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the OAuth 2.0 token revocation endpoint (RFC 7009).
 *
 * Validates RFC 7009 compliance including client authentication,
 * token ownership validation, privacy preservation, and permission-based
 * bypass functionality for administrative token revocation.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7009
 */
#[Group('simple_oauth_server_metadata')]
final class TokenRevocationEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The test OAuth client (confidential).
   *
   * @var \Drupal\consumers\Entity\Consumer
   */
  private Consumer $testClient;

  /**
   * The client secret for the test client.
   *
   * @var string
   */
  private string $clientSecret;

  /**
   * The test OAuth access token.
   *
   * @var \Drupal\simple_oauth\Entity\Oauth2Token
   */
  private Oauth2Token $testToken;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Rebuild router to ensure all routes are available.
    \Drupal::service('router.builder')->rebuild();

    // Create a confidential OAuth client (consumer).
    $this->clientSecret = 'test_client_secret_12345';
    $this->testClient = Consumer::create([
      'label' => 'Test Confidential Client',
      'client_id' => 'test_client_id',
      'secret' => $this->clientSecret,
      'is_default' => FALSE,
      'confidential' => TRUE,
    ]);
    $this->testClient->save();

    // Create a test access token owned by this client.
    $this->testToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'test_token_value_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $this->testToken->save();
  }

  /**
   * Tests successful token revocation with HTTP Basic Auth credentials.
   *
   * Validates that a client can revoke its own token using Basic Auth
   * for client authentication as specified in RFC 7009.
   */
  public function testSuccessfulRevocationWithBasicAuth(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'test_token_value_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    // Debug: Check response body if not 200.
    if ($response->getStatusCode() !== 200) {
      $body = (string) $response->getBody();
      $this->fail('Expected 200 but got ' . $response->getStatusCode() . '. Body: ' . substr($body, 0, 500));
    }

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was actually revoked in the database.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$this->testToken->id()]);
    $reloadedToken = $storage->load($this->testToken->id());
    $this->assertTrue($reloadedToken->isRevoked(), 'Token should be revoked after successful revocation request');
  }

  /**
   * Tests successful token revocation with POST body credentials.
   *
   * Validates that clients can provide authentication credentials in the
   * POST body (client_id and client_secret parameters) as an alternative
   * to HTTP Basic Auth.
   */
  public function testSuccessfulRevocationWithPostBodyCredentials(): void {
    // Create a new token for this test.
    $newToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'post_body_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $newToken->save();

    $response = $this->postRevocationRequest([
      'token' => 'post_body_token_12345',
      'client_id' => 'test_client_id',
      'client_secret' => $this->clientSecret,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$newToken->id()]);
    $reloadedToken = $storage->load($newToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests revocation with public client (no secret required).
   *
   * Public clients (confidential = FALSE) should be able to revoke
   * their own tokens without providing a client secret.
   */
  public function testPublicClientRevocation(): void {
    // Create a public client.
    $publicClient = Consumer::create([
      'label' => 'Test Public Client',
      'client_id' => 'public_client_id',
      'is_default' => FALSE,
      'confidential' => FALSE,
    ]);
    $publicClient->save();

    // Create a token for the public client.
    $publicToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $publicClient,
      'value' => 'public_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $publicToken->save();

    $response = $this->postRevocationRequest([
      'token' => 'public_token_12345',
      'client_id' => 'public_client_id',
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$publicToken->id()]);
    $reloadedToken = $storage->load($publicToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests authentication failure with invalid client credentials.
   *
   * RFC 7009 requires 401 Unauthorized when client authentication fails.
   */
  public function testAuthenticationFailureWithInvalidCredentials(): void {
    $credentials = base64_encode('test_client_id:wrong_secret');

    $response = $this->postRevocationRequest([
      'token' => 'test_token_value_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(401, $response->getStatusCode());

    $data = Json::decode($response->getBody());
    $this->assertEquals('invalid_client', $data['error']);

    // Verify token was NOT revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$this->testToken->id()]);
    $reloadedToken = $storage->load($this->testToken->id());
    $this->assertFalse($reloadedToken->isRevoked(), 'Token should not be revoked when authentication fails');
  }

  /**
   * Tests authentication failure with missing client credentials.
   *
   * Requests without any client authentication should return 401.
   */
  public function testAuthenticationFailureWithMissingCredentials(): void {
    $response = $this->postRevocationRequest([
      'token' => 'test_token_value_12345',
    ]);

    $this->assertEquals(401, $response->getStatusCode());

    $data = Json::decode($response->getBody());
    $this->assertEquals('invalid_client', $data['error']);

    // Verify token was NOT revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$this->testToken->id()]);
    $reloadedToken = $storage->load($this->testToken->id());
    $this->assertFalse($reloadedToken->isRevoked());
  }

  /**
   * Tests missing token parameter returns 400 Bad Request.
   *
   * RFC 7009 requires that the token parameter is present. Missing it
   * should result in a 400 error with invalid_request error code.
   */
  public function testMissingTokenParameter(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(400, $response->getStatusCode());

    $data = Json::decode($response->getBody());
    $this->assertEquals('invalid_request', $data['error']);
    $this->assertArrayHasKey('error_description', $data);
  }

  /**
   * Tests bypass permission allows admin to revoke any token.
   *
   * Users with 'bypass token revocation restrictions' permission
   * should be able to revoke tokens owned by other clients.
   */
  public function testBypassPermissionAllowsRevokingAnyToken(): void {
    // Create another client and token.
    $otherClient = Consumer::create([
      'label' => 'Other Client',
      'client_id' => 'other_client_id',
      'secret' => 'other_client_secret',
      'confidential' => TRUE,
    ]);
    $otherClient->save();

    $otherToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $otherClient,
      'value' => 'other_token_value_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $otherToken->save();

    // Grant bypass permission to a user and log in.
    $adminUser = $this->createUser(['bypass token revocation restrictions']);
    $this->drupalLogin($adminUser);

    // testClient tries to revoke otherClient's token
    // (should succeed with bypass).
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'other_token_value_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token WAS revoked (because of bypass permission).
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$otherToken->id()]);
    $reloadedToken = $storage->load($otherToken->id());
    $this->assertTrue($reloadedToken->isRevoked(), 'Token should be revoked when user has bypass permission');
  }

  /**
   * Tests ownership validation prevents unauthorized revocation.
   *
   * RFC 7009 privacy: endpoint returns 200 even when client doesn't own
   * the token, but the token should NOT actually be revoked.
   */
  public function testOwnershipValidationPreventsUnauthorizedRevocation(): void {
    // Create another client and token.
    $otherClient = Consumer::create([
      'label' => 'Other Client',
      'client_id' => 'other_client_id',
      'secret' => 'other_client_secret',
      'confidential' => TRUE,
    ]);
    $otherClient->save();

    $otherToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $otherClient,
      'value' => 'ownership_test_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $otherToken->save();

    // testClient tries to revoke otherClient's token (no bypass permission).
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'ownership_test_token_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    // RFC 7009: Return 200 (don't reveal ownership failure).
    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was NOT actually revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$otherToken->id()]);
    $reloadedToken = $storage->load($otherToken->id());
    $this->assertFalse($reloadedToken->isRevoked(), 'Token should not be revoked when client does not own it');
  }

  /**
   * Tests privacy preservation for non-existent tokens.
   *
   * RFC 7009 requires returning 200 even for non-existent tokens to
   * prevent token enumeration attacks.
   */
  public function testNonExistentTokenReturnsSuccess(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'nonexistent_token_that_does_not_exist',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    // RFC 7009: Should return 200 even for non-existent tokens (privacy).
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Tests idempotent revocation behavior.
   *
   * Revoking an already-revoked token should return 200 (idempotent).
   */
  public function testIdempotentRevocation(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    // First revocation.
    $response1 = $this->postRevocationRequest([
      'token' => 'test_token_value_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);
    $this->assertEquals(200, $response1->getStatusCode());

    // Verify token is revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$this->testToken->id()]);
    $reloadedToken = $storage->load($this->testToken->id());
    $this->assertTrue($reloadedToken->isRevoked());

    // Second revocation (same token, already revoked).
    $response2 = $this->postRevocationRequest([
      'token' => 'test_token_value_12345',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);
    $this->assertEquals(200, $response2->getStatusCode());

    // Token should still be revoked.
    $storage->resetCache([$this->testToken->id()]);
    $reloadedToken = $storage->load($this->testToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests revocation supports token_type_hint parameter.
   *
   * RFC 7009 allows clients to provide a hint about the token type.
   * This is optional and our implementation handles it gracefully.
   */
  public function testTokenTypeHintParameter(): void {
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    // Create a new token for this test.
    $hintToken = Oauth2Token::create([
      'bundle' => 'access_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'hint_test_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 3600,
      'status' => TRUE,
    ]);
    $hintToken->save();

    $response = $this->postRevocationRequest([
      'token' => 'hint_test_token_12345',
      'token_type_hint' => 'access_token',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify token was revoked (hint is accepted but not required).
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$hintToken->id()]);
    $reloadedToken = $storage->load($hintToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests revocation of refresh tokens.
   *
   * Both access tokens and refresh tokens should be revocable through
   * the same endpoint.
   */
  public function testRefreshTokenRevocation(): void {
    // Create a refresh token.
    $refreshToken = Oauth2Token::create([
      'bundle' => 'refresh_token',
      'auth_user_id' => $this->rootUser->id(),
      'client' => $this->testClient,
      'value' => 'test_refresh_token_12345',
      'scopes' => [],
      'expire' => \Drupal::time()->getRequestTime() + 7200,
      'status' => TRUE,
    ]);
    $refreshToken->save();

    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $response = $this->postRevocationRequest([
      'token' => 'test_refresh_token_12345',
      'token_type_hint' => 'refresh_token',
    ], [
      'Authorization' => 'Basic ' . $credentials,
    ]);

    $this->assertEquals(200, $response->getStatusCode());

    // Verify refresh token was revoked.
    $storage = \Drupal::entityTypeManager()->getStorage('oauth2_token');
    $storage->resetCache([$refreshToken->id()]);
    $reloadedToken = $storage->load($refreshToken->id());
    $this->assertTrue($reloadedToken->isRevoked());
  }

  /**
   * Tests server metadata includes revocation_endpoint.
   *
   * RFC 8414 server metadata should advertise the revocation endpoint URL.
   */
  public function testServerMetadataIncludesRevocationEndpoint(): void {
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $this->assertSession()->statusCodeEquals(200);

    $metadata = Json::decode($this->getSession()->getPage()->getContent());

    $this->assertArrayHasKey('revocation_endpoint', $metadata, 'Server metadata must include revocation_endpoint');
    $this->assertStringContainsString('/oauth/revoke', $metadata['revocation_endpoint']);

    // Verify it's an absolute URL.
    $this->assertStringStartsWith('http', $metadata['revocation_endpoint']);
  }

  /**
   * Tests only POST method is accepted.
   *
   * RFC 7009 requires POST method. Other methods should be rejected.
   */
  public function testOnlyPostMethodAccepted(): void {
    // The route already restricts to POST only, but verify it works.
    // GET request should fail.
    $credentials = base64_encode('test_client_id:' . $this->clientSecret);

    $this->drupalGet('/oauth/revoke', [
      'query' => ['token' => 'test_token_value_12345'],
      'headers' => ['Authorization' => 'Basic ' . $credentials],
    ]);

    // Should get 405 Method Not Allowed or 404 since route only allows POST.
    $statusCode = $this->getSession()->getStatusCode();
    $this->assertTrue(
      in_array($statusCode, [404, 405]),
      'GET requests to revocation endpoint should be rejected'
    );
  }

  /**
   * Helper method to POST to the revocation endpoint.
   *
   * @param array $formData
   *   The form data to POST.
   * @param array $headers
   *   Optional HTTP headers.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response object.
   */
  private function postRevocationRequest(array $formData = [], array $headers = []): object {
    $url = $this->getAbsoluteUrl('/oauth/revoke');
    $session = $this->getSession();

    $httpClient = $this->container->get('http_client');

    $options = [
      'form_params' => $formData,
      'http_errors' => FALSE,
    ];

    if (!empty($headers)) {
      $options['headers'] = $headers;
    }

    // Include session cookies to maintain user authentication state.
    $cookies = [];
    foreach ($session->getDriver()->getCookies() as $name => $value) {
      $cookies[] = "$name=$value";
    }
    if (!empty($cookies)) {
      if (!isset($options['headers'])) {
        $options['headers'] = [];
      }
      $options['headers']['Cookie'] = implode('; ', $cookies);
    }

    return $httpClient->request('POST', $url, $options);
  }

}
