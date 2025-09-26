---
id: 8
group: 'services'
dependencies: [4]
status: 'pending'
created: '2025-09-26'
skills: ['php', 'security']
---

# Implement User Code Generator Service

## Objective

Create the UserCodeGenerator service that generates cryptographically secure, human-readable user codes with configurable character sets and uniqueness validation.

## Skills Required

- **php**: Cryptographic random generation, string manipulation
- **security**: Entropy validation, secure random generation

## Acceptance Criteria

- [ ] Generates cryptographically secure user codes
- [ ] Uses configurable character set (excludes ambiguous chars)
- [ ] Formats codes for readability (XXXX-XXXX pattern)
- [ ] Ensures uniqueness across active codes
- [ ] Provides sufficient entropy (minimum requirements)
- [ ] Configurable code length via settings

## Technical Requirements

- Use random_bytes() for cryptographic security
- Implement retry logic for uniqueness
- Follow configured character set and length
- Format output for user readability

## Input Dependencies

- Device code repository from task 4 (for uniqueness checks)

## Output Artifacts

- src/Service/UserCodeGenerator.php
- Secure code generation with uniqueness validation

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Service structure:**

```php
class UserCodeGenerator {

  // Default charset excludes ambiguous characters (0, O, 1, I, l)
  private const DEFAULT_CHARSET = 'BCDFGHJKLMNPQRSTVWXYZ23456789';

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private DeviceCodeRepositoryInterface $deviceCodeRepository
  ) {}

  public function generateUserCode(): string {
    $config = $this->configFactory->get('simple_oauth_device_flow.settings');
    $length = $config->get('user_code_length') ?: 8;
    $charset = $config->get('user_code_charset') ?: self::DEFAULT_CHARSET;

    $maxAttempts = 10;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $code = $this->generateRandomCode($length, $charset);
      $formatted = $this->formatCode($code);

      // Check uniqueness
      if (!$this->codeExists($formatted)) {
        return $formatted;
      }
    }

    throw new \RuntimeException('Unable to generate unique user code after maximum attempts');
  }

  private function generateRandomCode(int $length, string $charset): string {
    $charsetLength = strlen($charset);
    $code = '';

    // Generate random bytes
    $randomBytes = random_bytes($length);

    for ($i = 0; $i < $length; $i++) {
      $index = ord($randomBytes[$i]) % $charsetLength;
      $code .= $charset[$index];
    }

    return $code;
  }

  private function formatCode(string $code): string {
    // Format as XXXX-XXXX for 8-character codes
    if (strlen($code) === 8) {
      return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    // For other lengths, add hyphens every 4 characters
    return chunk_split($code, 4, '-');
  }

  private function codeExists(string $userCode): bool {
    // Check if code already exists in active device codes
    $existing = $this->deviceCodeRepository->getDeviceCodeEntityByUserCode($userCode);
    return $existing !== null;
  }

  public function validateCodeFormat(string $userCode): bool {
    // Remove formatting and validate
    $cleaned = str_replace('-', '', strtoupper($userCode));
    $config = $this->configFactory->get('simple_oauth_device_flow.settings');
    $length = $config->get('user_code_length') ?: 8;
    $charset = $config->get('user_code_charset') ?: self::DEFAULT_CHARSET;

    if (strlen($cleaned) !== $length) {
      return false;
    }

    // Check all characters are valid
    for ($i = 0; $i < strlen($cleaned); $i++) {
      if (strpos($charset, $cleaned[$i]) === false) {
        return false;
      }
    }

    return true;
  }
}
```

**Security considerations:**

- Use random_bytes() for cryptographic security
- Ensure minimum entropy (8+ characters with good charset)
- Implement uniqueness checks to prevent collisions
- Validate input format securely
- Log generation failures for monitoring

**Configuration integration:**

- Read user_code_length from config (default: 8)
- Read user_code_charset from config (default: no ambiguous chars)
- Support different formatting patterns
</details>
