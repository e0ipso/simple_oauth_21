<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_client_registration\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\simple_oauth_client_registration\Normalizer\ClientRegistrationNormalizer;
use Drupal\simple_oauth_client_registration\Service\RegistrationTokenService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests registration URI syntax, DTO presence and grant/response validation.
 */
#[Group('simple_oauth_client_registration')]
final class ClientRegistrationTest extends UnitTestCase {

  /**
   * Tests redirect transport rules independently of parent field constraints.
   *
   * @param string $uri
   *   The redirect URI.
   * @param string|null $application_type
   *   The explicitly supplied application type, or NULL for omission.
   * @param bool $valid
   *   Whether registration's URI policy permits the URI.
   *
   * @dataProvider redirectProvider
   */
  #[DataProvider('redirectProvider')]
  public function testRedirectUris(string $uri, ?string $application_type, bool $valid): void {
    $registration = new ClientRegistration(redirectUris: [$uri], applicationType: $application_type);
    if (!$valid) {
      $this->expectException(\InvalidArgumentException::class);
    }
    $registration->validateRedirectUris();
    $this->assertTrue($valid);
  }

  /**
   * Supplies native and web URI transport cases.
   *
   * @return array
   *   URI, application type and expected result tuples.
   */
  public static function redirectProvider(): array {
    return [
      ['com.example.app:/oauth2redirect/example-provider', 'native', TRUE],
      ['com.example.app:/oauth2redirect/example-provider', NULL, TRUE],
      ['com.example.app://oauth/callback', 'native', TRUE],
      ['com.example.app:/callback', 'web', FALSE],
      ['https://example.org/callback', 'web', TRUE],
      ['http://example.org/callback', NULL, FALSE],
      ['http://127.0.0.1:43210/callback', 'native', TRUE],
      ['http://[::1]:43210/callback', 'native', TRUE],
      ['http://127.0.0.1:43210/callback', 'web', FALSE],
      ['http://loopback.example.org:43210/callback', 'native', FALSE],
      ['https://example.org/callback#fragment', 'web', FALSE],
      ['com.example.app:/callback#fragment', 'native', FALSE],
      ['/relative/callback', 'native', FALSE],
      ['com.example.app:', 'native', FALSE],
      ['com.example.app:/bad path', 'native', FALSE],
      ["com.example.app:/bad\npath", 'native', FALSE],
      ['https:/missing-host', 'native', FALSE],
    ];
  }

  /**
   * Tests new presence information does not change existing DTO serialization.
   */
  public function testContactsPresence(): void {
    $missing = ClientRegistration::fromArray([]);
    $empty = ClientRegistration::fromArray(['contacts' => []]);
    $direct = new ClientRegistration();
    $this->assertFalse($missing->contactsProvided);
    $this->assertFalse($direct->contactsProvided);
    $this->assertTrue($empty->contactsProvided);
    $this->assertSame($missing->toArray(), $empty->toArray());
    $response = $empty->withResponseFields('client', NULL, 'token', 'https://example.org/manage', 123);
    $this->assertTrue($response->contactsProvided);
  }

  /**
   * Tests removed grant types are rejected even when a plugin provides them.
   *
   * The denylist must hold independently of the grant plugin merge, so the
   * mocked manager reports plugins with the removed IDs.
   */
  public function testRemovedGrantTypesAreRejected(): void {
    $normalizer = $this->createNormalizer([
      'implicit' => [],
      'password' => [],
      'authorization_code' => [],
    ]);
    foreach (['implicit', 'password'] as $grant_type) {
      try {
        $normalizer->denormalize($this->metadata(['grant_types' => ['authorization_code', $grant_type]]), ClientRegistration::class);
        $this->fail('Grant type ' . $grant_type . ' must be rejected.');
      }
      catch (BadRequestHttpException $e) {
        $this->assertSame('Invalid grant type: ' . $grant_type, $e->getMessage());
      }
    }
    $registration = $normalizer->denormalize($this->metadata(['grant_types' => ['authorization_code']]), ClientRegistration::class);
    $this->assertSame(['authorization_code'], $registration->grantTypes);
  }

  /**
   * Tests only the exact response type "code" is accepted.
   *
   * @param string $response_type
   *   The requested response type.
   * @param bool $valid
   *   Whether registration accepts it.
   *
   * @dataProvider responseTypeProvider
   */
  #[DataProvider('responseTypeProvider')]
  public function testResponseTypes(string $response_type, bool $valid): void {
    $normalizer = $this->createNormalizer();
    if (!$valid) {
      $this->expectException(BadRequestHttpException::class);
      $this->expectExceptionMessage('Invalid response type: ' . $response_type);
    }
    $registration = $normalizer->denormalize($this->metadata(['response_types' => [$response_type]]), ClientRegistration::class);
    $this->assertSame([$response_type], $registration->responseTypes);
  }

  /**
   * Supplies accepted and rejected response types.
   *
   * @return array
   *   Response type and expected result tuples.
   */
  public static function responseTypeProvider(): array {
    return [
      'code' => ['code', TRUE],
      'token' => ['token', FALSE],
      'id_token' => ['id_token', FALSE],
      'code id_token' => ['code id_token', FALSE],
      'code token' => ['code token', FALSE],
    ];
  }

  /**
   * Builds a normalizer with a grant manager reporting the given plugins.
   *
   * @param array $definitions
   *   The grant plugin definitions keyed by plugin ID.
   */
  private function createNormalizer(array $definitions = []): ClientRegistrationNormalizer {
    $grant_manager = $this->createMock(Oauth2GrantManagerInterface::class);
    $grant_manager->method('getDefinitions')->willReturn($definitions);
    return new ClientRegistrationNormalizer(
      new RegistrationTokenService($this->createMock(Connection::class), $this->createMock(LoggerInterface::class)),
      $this->createMock(FileUrlGeneratorInterface::class),
      $grant_manager,
    );
  }

  /**
   * Builds otherwise valid registration metadata.
   *
   * @param array $overrides
   *   Metadata to merge over the valid baseline.
   */
  private function metadata(array $overrides): array {
    return $overrides + ['redirect_uris' => ['https://example.org/callback']];
  }

}
