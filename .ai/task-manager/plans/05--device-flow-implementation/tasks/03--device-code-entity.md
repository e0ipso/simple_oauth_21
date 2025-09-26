---
id: 3
group: 'entity-implementation'
dependencies: [2]
status: 'pending'
created: '2025-09-26'
skills: ['drupal-backend', 'php']
---

# Implement Device Code Entity

## Objective

Create the DeviceCode entity class that implements both Drupal's ContentEntityInterface and league/oauth2-server's DeviceCodeEntityInterface for dual compatibility.

## Skills Required

- **drupal-backend**: Drupal entity system, annotations, field definitions
- **php**: Interface implementation, method signatures

## Acceptance Criteria

- [ ] DeviceCode entity extends ContentEntityBase
- [ ] Implements DeviceCodeEntityInterface from league/oauth2-server
- [ ] Proper entity annotations and metadata
- [ ] All required methods from DeviceCodeEntityInterface implemented
- [ ] Field definitions for all database columns
- [ ] Entity access controls and permissions

## Technical Requirements

- Dual interface implementation (Drupal + league/oauth2-server)
- Entity annotation with proper metadata
- Field definitions matching database schema
- Method implementations for league interface

## Input Dependencies

- Database schema from task 2

## Output Artifacts

- src/Entity/DeviceCode.php
- Entity annotation and metadata
- Method implementations for DeviceCodeEntityInterface

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Entity structure:**

```php
/**
 * @ContentEntityType(
 *   id = "oauth2_device_code",
 *   label = @Translation("Device Code"),
 *   base_table = "oauth2_device_code",
 *   entity_keys = {
 *     "id" = "device_code",
 *     "uuid" = "uuid"
 *   }
 * )
 */
class DeviceCode extends ContentEntityBase implements DeviceCodeEntityInterface {
  // Implement league/oauth2-server interface methods
}
```

**Required DeviceCodeEntityInterface methods:**

- getUserCode(): string
- setUserCode(string $userCode): void
- getVerificationUri(): string
- setVerificationUri(string $verificationUri): void
- getVerificationUriComplete(): string
- getLastPolledAt(): ?DateTimeImmutable
- setLastPolledAt(DateTimeImmutable $lastPolledAt): void
- getInterval(): int
- setInterval(int $interval): void
- getUserApproved(): bool
- setUserApproved(bool $userApproved): void

**Also implement TokenInterface methods:**

- getIdentifier(): string
- setIdentifier($identifier): void
- getExpiryDateTime(): DateTimeImmutable
- setExpiryDateTime(DateTimeImmutable $dateTime): void
- getUserIdentifier(): ?string
- setUserIdentifier($identifier): void
- getClient(): ClientEntityInterface
- setClient(ClientEntityInterface $client): void
- getScopes(): ScopeEntityInterface[]
- addScope(ScopeEntityInterface $scope): void

**Field definitions:**
Use baseFieldDefinitions() to define all entity fields matching the database schema.

</details>
