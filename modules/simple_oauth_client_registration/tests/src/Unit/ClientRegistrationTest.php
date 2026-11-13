<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_client_registration\Unit;

use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests registration URI syntax and compatible DTO presence semantics.
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

}
