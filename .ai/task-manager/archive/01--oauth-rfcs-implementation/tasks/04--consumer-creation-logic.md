---
id: 4
group: 'rfc-7591-client-registration'
dependencies: [1, 2]
status: 'pending'
created: '2025-09-16'
skills: ['drupal-backend', 'entity-api']
complexity_score: 4.0
---

# Consumer Creation Logic

## Objective

Implement the core Consumer entity creation logic for client registration, using the proven `Consumer::create($values)` pattern from `ConsumerEntityTest.php` with proper field mapping for RFC 7591 metadata.

## Skills Required

- **drupal-backend**: Drupal service layer, entity manipulation
- **entity-api**: Consumer entity creation, field value mapping

## Acceptance Criteria

- [ ] Registration service class created in `src/Service/`
- [ ] `createConsumer()` method following `ConsumerEntityTest.php` patterns
- [ ] Proper mapping of RFC 7591 request fields to Consumer entity fields
- [ ] Unique client_id generation
- [ ] Optional client_secret generation for confidential clients
- [ ] Consumer entity saved successfully with all metadata

## Technical Requirements

**Service Class:**

- `RegistrationService` in `src/Service/`
- Method: `createConsumer(array $clientData): ConsumerInterface`

**Field Mapping:**

- Map RFC 7591 fields to Consumer entity fields added in Task 2
- Handle optional vs required fields per RFC 7591
- Set appropriate defaults for Drupal-specific fields

## Input Dependencies

- Task 1: Module structure for service placement
- Task 2: Consumer entity fields must exist

## Output Artifacts

- Registration service with Consumer creation logic
- Field mapping between RFC 7591 and Consumer entity

## Implementation Notes

<details>
<summary>Detailed Implementation Instructions</summary>

Copy the exact Consumer creation pattern from `ConsumerEntityTest.php`:

**Service Structure:**

```php
<?php

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class RegistrationService {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public function createConsumer(array $clientData): ConsumerInterface {
    // Use Consumer::create($values) pattern from test
  }
}
```

**Consumer Creation Pattern (from ConsumerEntityTest.php line 84):**

```php
$values = [
  'client_id' => $this->generateClientId(),
  'label' => $clientData['client_name'] ?? 'Registered Client',
  'grant_types' => $clientData['grant_types'] ?? ['authorization_code'],
  'redirect' => $clientData['redirect_uris'] ?? [],
  'confidential' => $clientData['token_endpoint_auth_method'] !== 'none',
  // Map RFC 7591 fields to Consumer fields
  'client_name' => $clientData['client_name'] ?? '',
  'client_uri' => $clientData['client_uri'] ?? '',
  'logo_uri' => $clientData['logo_uri'] ?? '',
  // ... other fields
];

$consumer = Consumer::create($values);
$consumer->save();
```

**Client ID Generation:**

- Use `\Drupal\Component\Utility\Crypt::randomBytesBase64(32)` or similar
- Ensure uniqueness by checking existing Consumer entities

**Field Defaults:**

- Set reasonable defaults from ConsumerEntityTest.php (lines 111-127)
- access_token_expiration: 300
- refresh_token_expiration: 1209600
- confidential: TRUE (unless specified otherwise)
</details>
