<?php

namespace Drupal\simple_oauth_native_apps\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for validating native app redirect URIs per RFC 8252.
 */
class RedirectUriValidator {

  /**
   * Regular expression for custom URI scheme validation.
   *
   * Following RFC 3986 and reverse domain notation patterns.
   */
  const CUSTOM_SCHEME_PATTERN = '/^[a-z][a-z0-9+.-]*:\/\//i';

  /**
   * Regular expression for reverse domain notation validation.
   *
   * Matches patterns like "com.example.app" in schemes.
   */
  const REVERSE_DOMAIN_PATTERN = '/^[a-z][a-z0-9.-]*\.[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)*$/i';

  /**
   * Constructs a new RedirectUriValidator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Validates a redirect URI for native apps.
   *
   * @param string $uri
   *   The redirect URI to validate.
   * @param string $validation_method
   *   The validation method (standard|exact_match|native_enhanced).
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public function validateRedirectUri(string $uri, string $validation_method = 'native_enhanced'): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Skip native validation if not enforced.
    if (!$config->get('enforce_native_security') && $validation_method !== 'native_enhanced') {
      return $this->validateStandardRedirectUri($uri);
    }

    // Parse URI first to check structure.
    $parsed = parse_url($uri);
    if (!$parsed || !isset($parsed['scheme'])) {
      $this->logger->warning('Unable to parse URI or missing scheme: @uri', ['@uri' => $uri]);
      return FALSE;
    }

    // For standard schemes, use filter_var validation.
    $scheme = strtolower($parsed['scheme']);
    if (in_array($scheme, ['http', 'https', 'ftp', 'ftps'])) {
      if (!filter_var($uri, FILTER_VALIDATE_URL)) {
        $this->logger->warning('Invalid URI format: @uri', ['@uri' => $uri]);
        return FALSE;
      }
    }
    // For custom schemes, just validate that we have a valid scheme name.
    else {
      // Custom schemes should follow RFC 3986:
      // scheme = ALPHA *( ALPHA / DIGIT / "+" / "-" / "." )
      if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*$/', $scheme)) {
        $this->logger->warning('Invalid custom scheme format: @scheme in @uri', ['@scheme' => $scheme, '@uri' => $uri]);
        return FALSE;
      }
    }

    // Handle different URI types based on scheme.
    return match ($scheme) {
      'http', 'https' => $this->validateLoopbackInterface($uri),
      default => $this->validateCustomScheme($uri),
    };
  }

  /**
   * Validates custom URI schemes (RFC 8252 Section 7.1).
   *
   * @param string $uri
   *   The URI with custom scheme.
   *
   * @return bool
   *   TRUE if valid custom scheme.
   */
  public function validateCustomScheme(string $uri): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    if ($config->get('allow.custom_uri_schemes') !== 'native') {
      $this->logger->warning('Custom URI schemes are disabled: @uri', ['@uri' => $uri]);
      return FALSE;
    }

    // Basic scheme format validation.
    if (!preg_match(self::CUSTOM_SCHEME_PATTERN, $uri)) {
      $this->logger->warning('Invalid custom scheme format: @uri', ['@uri' => $uri]);
      return FALSE;
    }

    $parsed = parse_url($uri);
    $scheme = $parsed['scheme'];

    // Security check: Block dangerous schemes.
    if ($this->isDangerousScheme($scheme)) {
      $this->logSecurityViolation('Dangerous scheme detected', $uri);
      return FALSE;
    }

    // Validate reverse domain notation for mobile app schemes.
    if ($this->isReverseDomainScheme($scheme)) {
      return $this->validateReverseDomainScheme($scheme);
    }

    // Allow standard native app schemes.
    if ($this->isStandardNativeScheme($scheme)) {
      return TRUE;
    }

    // Allow any custom scheme that passes basic validation (RFC 8252 allows
    // custom schemes). Additional validation was already done above for
    // dangerous schemes and format.
    return TRUE;
  }

  /**
   * Validates loopback interface redirects (RFC 8252 Section 7.3).
   *
   * @param string $uri
   *   The loopback interface URI.
   *
   * @return bool
   *   TRUE if valid loopback interface.
   */
  public function validateLoopbackInterface(string $uri): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    if ($config->get('allow.loopback_redirects') !== 'native') {
      $this->logger->warning('Loopback redirects are disabled: @uri', ['@uri' => $uri]);
      return FALSE;
    }

    $parsed = parse_url($uri);

    if (!$parsed || !isset($parsed['host'])) {
      $this->logger->warning('No host in loopback URI: @uri', ['@uri' => $uri]);
      return FALSE;
    }

    // Must be HTTP (not HTTPS for loopback per RFC 8252).
    if (!isset($parsed['scheme']) || $parsed['scheme'] !== 'http') {
      $this->logger->warning('Loopback interface must use http:// scheme, got @scheme in URI @uri', [
        '@scheme' => $parsed['scheme'] ?? 'none',
        '@uri' => $uri,
      ]);
      return FALSE;
    }

    $host = $parsed['host'];

    // Validate IPv4 loopback.
    if ($this->isIpv4Loopback($host)) {
      return $this->validateLoopbackPort($parsed) && $this->validateLoopbackSecurity($uri);
    }

    // Validate IPv6 loopback.
    if ($this->isIpv6Loopback($host)) {
      return $this->validateLoopbackPort($parsed) && $this->validateLoopbackSecurity($uri);
    }

    $this->logger->warning('Invalid loopback host: @host in URI @uri. Only 127.0.0.1 and [::1] are allowed', [
      '@host' => $host,
      '@uri' => $uri,
    ]);
    return FALSE;
  }

  /**
   * Performs exact string matching validation.
   *
   * @param string $registered_uri
   *   The registered redirect URI.
   * @param string $request_uri
   *   The request redirect URI.
   *
   * @return bool
   *   TRUE if exact match.
   */
  public function validateExactMatch(string $registered_uri, string $request_uri): bool {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    if (!$config->get('require_exact_redirect_match')) {
      // Fallback to standard validation if exact matching is disabled.
      return $this->validateStandardRedirectUri($request_uri);
    }

    // Perform case-sensitive exact string comparison.
    $is_exact = $registered_uri === $request_uri;

    if (!$is_exact) {
      $this->logger->warning('Redirect URI mismatch - registered: @registered, request: @request', [
        '@registered' => $registered_uri,
        '@request' => $request_uri,
      ]);
    }

    return $is_exact;
  }

  /**
   * Validates multiple redirect URIs.
   *
   * @param array $uris
   *   Array of redirect URIs to validate.
   * @param string $validation_method
   *   The validation method to use.
   *
   * @return array
   *   Array with 'valid' and 'invalid' keys containing the respective URIs.
   */
  public function validateMultipleUris(array $uris, string $validation_method = 'native_enhanced'): array {
    $results = [
      'valid' => [],
      'invalid' => [],
    ];

    foreach ($uris as $uri) {
      if ($this->validateRedirectUri($uri, $validation_method)) {
        $results['valid'][] = $uri;
      }
      else {
        $results['invalid'][] = $uri;
      }
    }

    return $results;
  }

  /**
   * Checks if a scheme follows reverse domain notation.
   *
   * @param string $scheme
   *   The URI scheme to check.
   *
   * @return bool
   *   TRUE if it appears to be reverse domain notation.
   */
  protected function isReverseDomainScheme(string $scheme): bool {
    // Simple heuristic: contains dots and looks like reverse domain.
    return strpos($scheme, '.') !== FALSE && preg_match(self::REVERSE_DOMAIN_PATTERN, $scheme);
  }

  /**
   * Validates reverse domain notation schemes.
   *
   * @param string $scheme
   *   The scheme to validate.
   *
   * @return bool
   *   TRUE if valid reverse domain scheme.
   */
  protected function validateReverseDomainScheme(string $scheme): bool {
    if (!preg_match(self::REVERSE_DOMAIN_PATTERN, $scheme)) {
      $this->logger->warning('Invalid reverse domain notation: @scheme', ['@scheme' => $scheme]);
      return FALSE;
    }

    // Additional security checks for mobile app schemes.
    $parts = explode('.', $scheme);

    // Must have at least 3 parts (com.company.app).
    if (count($parts) < 3) {
      $this->logger->warning('Reverse domain scheme too short, must have at least 3 parts: @scheme', ['@scheme' => $scheme]);
      return FALSE;
    }

    // First part should be a valid TLD.
    $tld = $parts[0];
    if (!$this->isValidTld($tld)) {
      $this->logger->warning('Invalid TLD in reverse domain scheme: @tld', ['@tld' => $tld]);
      return FALSE;
    }

    // Validate each part of the domain.
    foreach ($parts as $part) {
      if (!preg_match('/^[a-z0-9-]+$/i', $part) || strlen($part) < 1) {
        $this->logger->warning('Invalid domain part in reverse domain scheme: @part', ['@part' => $part]);
        return FALSE;
      }

      // Parts cannot start or end with hyphen.
      if (strpos($part, '-') === 0 || substr($part, -1) === '-') {
        $this->logger->warning('Domain part cannot start or end with hyphen: @part', ['@part' => $part]);
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Validates IPv4 loopback interface addresses.
   *
   * @param string $host
   *   The host to validate.
   *
   * @return bool
   *   TRUE if valid IPv4 loopback address.
   */
  private function isIpv4Loopback(string $host): bool {
    // Only 127.0.0.1 is allowed for RFC 8252 compliance.
    // Note: We don't allow entire 127.0.0.0/8 range or 'localhost'.
    return $host === '127.0.0.1';
  }

  /**
   * Validates IPv6 loopback interface addresses.
   *
   * @param string $host
   *   The host to validate.
   *
   * @return bool
   *   TRUE if valid IPv6 loopback address.
   */
  private function isIpv6Loopback(string $host): bool {
    // Remove brackets if present.
    $host = trim($host, '[]');

    // IPv6 loopback variations.
    $ipv6_loopbacks = [
      '::1',
      '0:0:0:0:0:0:0:1',
    ];

    return in_array(strtolower($host), $ipv6_loopbacks);
  }

  /**
   * Validates port numbers for loopback interfaces.
   *
   * @param array $parsed
   *   Parsed URL components.
   *
   * @return bool
   *   TRUE if port is valid or not specified.
   */
  private function validateLoopbackPort(array $parsed): bool {
    // Port is optional for loopback interfaces.
    if (!isset($parsed['port'])) {
      return TRUE;
    }

    $port = $parsed['port'];

    // Validate port range.
    if (!is_numeric($port) || $port < 1 || $port > 65535) {
      $this->logger->warning('Invalid port number @port in loopback URI', ['@port' => $port]);
      return FALSE;
    }

    // Log info for system ports but allow them.
    if ((int) $port < 1024) {
      $this->logger->info('System port @port used in loopback redirect URI', ['@port' => $port]);
    }

    return TRUE;
  }

  /**
   * Validates security aspects of loopback URIs.
   *
   * @param string $uri
   *   The URI to validate for security issues.
   *
   * @return bool
   *   TRUE if URI passes security validation.
   */
  private function validateLoopbackSecurity(string $uri): bool {
    $parsed = parse_url($uri);

    // Ensure no malicious query parameters.
    if (isset($parsed['query']) && $this->containsMaliciousQuery($parsed['query'])) {
      $this->logger->error('Malicious query parameters detected in loopback URI: @uri', ['@uri' => $uri]);
      return FALSE;
    }

    // Ensure path is reasonable.
    if (isset($parsed['path']) && $this->containsSuspiciousPath($parsed['path'])) {
      $this->logger->error('Suspicious path detected in loopback URI: @uri', ['@uri' => $uri]);
      return FALSE;
    }

    // Validate hostname security.
    return $this->validateHostnameSecurity($parsed['host']);
  }

  /**
   * Checks for malicious query parameters.
   *
   * @param string $query
   *   The query string to check.
   *
   * @return bool
   *   TRUE if malicious patterns found.
   */
  private function containsMaliciousQuery(string $query): bool {
    $malicious_patterns = [
      '/javascript:/i',
      '/data:/i',
      '/vbscript:/i',
      '/<script/i',
      '/eval\(/i',
    ];

    foreach ($malicious_patterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks for suspicious path patterns.
   *
   * @param string $path
   *   The path to check.
   *
   * @return bool
   *   TRUE if suspicious patterns found.
   */
  private function containsSuspiciousPath(string $path): bool {
    // Block path traversal attempts.
    if (strpos($path, '../') !== FALSE || strpos($path, '..\\') !== FALSE) {
      return TRUE;
    }

    // Block extremely long paths.
    if (strlen($path) > 1000) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Validates hostname security for loopback interfaces.
   *
   * @param string $host
   *   The host to validate.
   *
   * @return bool
   *   TRUE if host passes security validation.
   */
  private function validateHostnameSecurity(string $host): bool {
    // Check if it looks like a hostname (contains letters).
    if (preg_match('/[a-zA-Z]/', $host)) {
      // Special case: IPv6 addresses might contain letters.
      $host_clean = trim($host, '[]');
      if (!$this->isValidIpv6($host_clean)) {
        $this->logger->error('Hostname used instead of IP address in loopback URI: @host', ['@host' => $host]);
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Validates IPv6 address format.
   *
   * @param string $host
   *   The host to validate as IPv6.
   *
   * @return bool
   *   TRUE if valid IPv6 address.
   */
  private function isValidIpv6(string $host): bool {
    // Remove brackets.
    $host = trim($host, '[]');

    // Use PHP's built-in validation.
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE;
  }

  /**
   * Gets detailed error message for loopback validation failures.
   *
   * @param string $uri
   *   The URI that failed validation.
   *
   * @return string|null
   *   Error message or NULL if no error.
   */
  public function getLoopbackValidationError(string $uri): ?string {
    $parsed = parse_url($uri);

    if (!$parsed) {
      return 'Invalid URI format for loopback interface';
    }

    if (!isset($parsed['scheme']) || $parsed['scheme'] !== 'http') {
      return 'Loopback interfaces must use http:// scheme (not https://)';
    }

    if (!isset($parsed['host'])) {
      return 'Missing host in loopback URI';
    }

    $host = trim($parsed['host'], '[]');

    if ($host !== '127.0.0.1' && $host !== '::1') {
      return "Invalid loopback address '{$host}'. Only 127.0.0.1 and [::1] are allowed";
    }

    if (isset($parsed['port'])) {
      $port = (int) $parsed['port'];
      if ($port < 1 || $port > 65535) {
        return "Invalid port number {$port}. Must be between 1 and 65535";
      }
    }

    return NULL;
  }

  /**
   * Validates standard redirect URIs (fallback method).
   *
   * @param string $uri
   *   The URI to validate.
   *
   * @return bool
   *   TRUE if valid standard URI.
   */
  protected function validateStandardRedirectUri(string $uri): bool {
    // Basic URL validation.
    if (!filter_var($uri, FILTER_VALIDATE_URL)) {
      return FALSE;
    }

    $parsed = parse_url($uri);

    // Must have scheme and host (for http/https) or just scheme for custom.
    if (!isset($parsed['scheme'])) {
      return FALSE;
    }

    $scheme = strtolower($parsed['scheme']);

    // For http/https, require host.
    if (in_array($scheme, ['http', 'https']) && !isset($parsed['host'])) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if a scheme is dangerous and should be blocked.
   *
   * @param string $scheme
   *   The scheme to check.
   *
   * @return bool
   *   TRUE if the scheme is dangerous.
   */
  protected function isDangerousScheme(string $scheme): bool {
    $dangerous_schemes = [
      'javascript',
      'data',
      'vbscript',
      'file',
      'ftp',
      'about',
      'chrome',
      'chrome-extension',
      'moz-extension',
      'safari-extension',
      'mailto',
      'tel',
      'sms',
      'blob',
      'filesystem',
      'view-source',
    ];

    return in_array(strtolower($scheme), $dangerous_schemes);
  }

  /**
   * Checks if a scheme is a standard native app scheme.
   *
   * @param string $scheme
   *   The scheme to check.
   *
   * @return bool
   *   TRUE if it's a standard native app scheme.
   */
  protected function isStandardNativeScheme(string $scheme): bool {
    $native_patterns = [
      // App schemes ending in 'app'.
      '/^[a-z][a-z0-9\-]*app$/i',

      // Platform-specific schemes.
    // Microsoft.
      '/^ms-app$/i',
    // Various platforms.
      '/^x-app-id$/i',

      // Hyphenated schemes (mobile-app, custom-scheme).
      '/^[a-z]+-[a-z0-9-]*$/i',

      // Alphanumeric schemes with common patterns.
    // test123, app1, etc.
      '/^[a-z]+[0-9]+$/i',
    // customscheme, mobilescheme, etc. cspell:ignore customscheme mobilescheme
      '/^[a-z]{5,}scheme$/i',
    ];

    foreach ($native_patterns as $pattern) {
      if (preg_match($pattern, $scheme)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Validates if a TLD is valid for reverse domain notation.
   *
   * @param string $tld
   *   The top-level domain to validate.
   *
   * @return bool
   *   TRUE if valid TLD.
   */
  protected function isValidTld(string $tld): bool {
    $valid_tlds = [
      'com', 'org', 'net', 'edu', 'gov', 'mil',
      'co', 'app', 'dev', 'io', 'me', 'us',
      'uk', 'ca', 'au', 'de', 'fr', 'it',
      'es', 'jp', 'kr', 'cn', 'in', 'br',
      'info', 'biz', 'name', 'pro', 'museum',
    ];

    return in_array(strtolower($tld), $valid_tlds);
  }

  /**
   * Logs a security violation.
   *
   * @param string $reason
   *   The reason for the security violation.
   * @param string $uri
   *   The URI that caused the violation.
   */
  protected function logSecurityViolation(string $reason, string $uri): void {
    $this->logger->warning('Native app security violation: @reason for URI: @uri', [
      '@reason' => $reason,
      '@uri' => $uri,
    ]);
  }

  /**
   * Gets a validation error message for a URI.
   *
   * @param string $uri
   *   The URI to validate and get error for.
   *
   * @return string|null
   *   The validation error message, or NULL if valid.
   */
  public function getValidationError(string $uri): ?string {
    $config = $this->configFactory->get('simple_oauth_native_apps.settings');

    // Basic URL validation.
    if (!filter_var($uri, FILTER_VALIDATE_URL)) {
      return 'Invalid URI format';
    }

    $parsed = parse_url($uri);
    if (!$parsed || !isset($parsed['scheme'])) {
      return 'Invalid URI format or missing scheme';
    }

    $scheme = strtolower($parsed['scheme']);

    // Handle HTTP/HTTPS schemes.
    if (in_array($scheme, ['http', 'https'])) {
      if (!$config->get('allow.loopback_redirects')) {
        return 'Loopback redirects are disabled';
      }
      if (!$this->validateLoopbackInterface($uri)) {
        return 'Invalid loopback interface. Only 127.0.0.1, localhost, or [::1] are allowed';
      }
      // Valid.
      return NULL;
    }

    // Handle custom schemes.
    if (!$config->get('allow.custom_uri_schemes')) {
      return 'Custom URI schemes are disabled';
    }

    if (!preg_match(self::CUSTOM_SCHEME_PATTERN, $uri)) {
      // Check for specific common issues.
      if (preg_match('/^[0-9]/', $scheme)) {
        return "Scheme '{$scheme}' must start with a letter";
      }
      return 'Invalid custom scheme format';
    }

    if ($this->isDangerousScheme($scheme)) {
      return "Dangerous scheme '{$scheme}' not allowed for security reasons";
    }

    if ($this->isReverseDomainScheme($scheme)) {
      if (!$this->validateReverseDomainScheme($scheme)) {
        if (!preg_match(self::REVERSE_DOMAIN_PATTERN, $scheme)) {
          return "Invalid reverse domain notation in scheme '{$scheme}'";
        }
        $parts = explode('.', $scheme);
        if (count($parts) < 3) {
          return "Reverse domain scheme '{$scheme}' must have at least 3 parts (com.company.app)";
        }
        if (!$this->isValidTld($parts[0])) {
          return "Invalid TLD '{$parts[0]}' in reverse domain scheme '{$scheme}'";
        }
        return "Invalid reverse domain notation in scheme '{$scheme}'";
      }
    }
    elseif (!$this->isStandardNativeScheme($scheme)) {
      if (strlen($scheme) < 3 || strlen($scheme) > 50) {
        return "Scheme '{$scheme}' length must be between 3 and 50 characters";
      }
      if (!preg_match('/^[a-z]/i', $scheme)) {
        return "Scheme '{$scheme}' must start with a letter";
      }
      return "Scheme '{$scheme}' is not a recognized native app scheme pattern";
    }

    // No error, URI is valid.
    return NULL;
  }

}
