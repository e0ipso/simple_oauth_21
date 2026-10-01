<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_oauth_server_metadata\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that discovery documents stay at the document root when multilingual.
 *
 * RFC 8414, RFC 9728 and OpenID Connect Discovery place their documents at a
 * fixed path below the host, so URL prefix language negotiation must not move
 * them, advertise a prefixed copy, or serve them from a second URL.
 *
 * @see \Drupal\simple_oauth_server_metadata\PathProcessor\WellKnownPathProcessor
 */
#[Group('simple_oauth_server_metadata')]
final class WellKnownLanguagePrefixTest extends BrowserTestBase {

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
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The discovery documents this module serves.
   */
  private const DISCOVERY_PATHS = [
    '/.well-known/oauth-authorization-server',
    '/.well-known/oauth-protected-resource',
    '/.well-known/openid-configuration',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('nl')->save();

    // Negotiate the interface language from a URL prefix, which is what puts
    // a language prefix on generated paths.
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => 0])
      ->save();
    $this->rebuildContainer();
  }

  /**
   * The documents stay reachable, unprefixed, and served from one URL only.
   */
  public function testDiscoveryDocumentsIgnoreLanguagePrefix(): void {
    foreach (self::DISCOVERY_PATHS as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }

    // A prefixed path must not serve a second copy of the document. The exact
    // status depends on the web server -- Drupal returns 404, while Apache
    // refuses the path outright because core's .htaccess only exempts
    // `.well-known` as the leading segment -- so assert only that the document
    // is not served there.
    foreach (self::DISCOVERY_PATHS as $path) {
      $this->drupalGet('/nl' . $path);
      $this->assertSession()->statusCodeNotEquals(200);
    }

    // The document must not advertise a prefixed URL for itself, or clients
    // following it land on a path the specifications do not define.
    $this->drupalGet('/.well-known/oauth-authorization-server');
    $metadata = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($metadata);
    $self_reference = $metadata['oauth_authorization_server_metadata_endpoint'] ?? '';
    $this->assertStringEndsWith(
      '/.well-known/oauth-authorization-server',
      $self_reference,
      'The metadata endpoint advertises itself without a language prefix.',
    );
  }

  /**
   * Paths outside the discovery documents keep their language prefix.
   */
  public function testOtherPathsAreUnaffected(): void {
    $url = $this->container->get('url_generator')
      ->generateFromRoute('user.login', [], ['language' => ConfigurableLanguage::load('nl')]);

    $this->assertStringContainsString(
      '/nl/',
      $url,
      'Language negotiation still prefixes paths this module does not own.',
    );
  }

}
