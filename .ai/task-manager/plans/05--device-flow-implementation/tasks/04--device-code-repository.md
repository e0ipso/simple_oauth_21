---
id: 4
group: 'entity-implementation'
dependencies: [3]
status: 'pending'
created: '2025-09-26'
skills: ['drupal-backend', 'php']
---

# Implement Device Code Repository

## Objective

Create the DeviceCodeRepository class that implements DeviceCodeRepositoryInterface to bridge league/oauth2-server with Drupal's entity storage system.

## Skills Required

- **drupal-backend**: Entity storage, Drupal repository patterns
- **php**: Interface implementation, dependency injection

## Acceptance Criteria

- [ ] Repository implements DeviceCodeRepositoryInterface
- [ ] Uses Drupal entity storage for persistence
- [ ] Proper dependency injection and service registration
- [ ] All interface methods correctly implemented
- [ ] Error handling for storage operations
- [ ] Time-constant comparison for security

## Technical Requirements

- Implement all DeviceCodeRepositoryInterface methods
- Use Drupal's entity storage system
- Integrate with Simple OAuth patterns
- Handle entity loading, saving, and querying

## Input Dependencies

- DeviceCode entity from task 3

## Output Artifacts

- src/Repository/DeviceCodeRepository.php
- Service container integration
- Entity storage bridge implementation

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Repository pattern (study simple_oauth repository implementations):**

```php
class DeviceCodeRepository implements DeviceCodeRepositoryInterface {
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private LoggerInterface $logger
  ) {}

  public function getNewDeviceCode(): DeviceCodeEntityInterface {
    return $this->entityTypeManager
      ->getStorage('oauth2_device_code')
      ->create();
  }

  public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void {
    // Save entity using Drupal storage
  }

  public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface {
    // Load by device code with proper error handling
  }

  public function revokeDeviceCode(string $codeId): void {
    // Mark as revoked
  }

  public function isDeviceCodeRevoked(string $codeId): bool {
    // Check revocation status
  }
}
```

**Security considerations:**

- Use time-constant comparison for user codes (hash_equals())
- Validate entity types before returning
- Handle storage exceptions gracefully
- Log security-relevant events

**Service registration:**
Register in services.yml as 'simple_oauth_device_flow.repositories.device_code'

**Error handling:**

- Catch storage exceptions
- Return null for not found (per interface contract)
- Log errors appropriately
- Throw UniqueTokenIdentifierConstraintViolationException for duplicates
</details>
