---
id: 2
group: "event-infrastructure"
dependencies: [1]
status: "pending"
created: 2025-11-03
skills:
  - "drupal-backend"
  - "symfony-events"
---
# Integrate Event Dispatch into ResourceMetadataService

## Objective

Modify `ResourceMetadataService` to dispatch the `ResourceMetadataEvent` during metadata generation, allowing event subscribers to modify metadata before it's returned to clients.

## Skills Required

- **drupal-backend**: Drupal service container, dependency injection, and service modification
- **symfony-events**: Event dispatcher integration and event dispatching patterns

## Acceptance Criteria

- [ ] `EventDispatcherInterface` injected into `ResourceMetadataService` constructor
- [ ] Event dispatched in `getResourceMetadata()` after adding configured fields, before filtering
- [ ] Service definition updated in `simple_oauth_server_metadata.services.yml`
- [ ] Existing functionality preserved (backwards compatible)
- [ ] All existing tests still pass
- [ ] Code uses proper typing and follows project standards

## Technical Requirements

**Service Constructor Modification:**

Add `EventDispatcherInterface` parameter to constructor:

```php
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

public function __construct(
  private readonly ConfigFactoryInterface $configFactory,
  private readonly EndpointDiscoveryService $endpointDiscovery,
  private readonly EventDispatcherInterface $eventDispatcher,
) {
  // Existing cache tags/contexts...
}
```

**Event Dispatch in getResourceMetadata():**

After line where `addConfigurableFields()` is called, add:

```php
// Add admin-configured fields.
$config = $this->configFactory->get('simple_oauth_server_metadata.settings');
$this->addConfigurableFields($metadata, $config, $config_override);

// Dispatch event to allow modules to modify metadata.
$event = new ResourceMetadataEvent($metadata, $config_override);
$this->eventDispatcher->dispatch($event, ResourceMetadataEvents::BUILD);
$metadata = $event->getMetadata();

// Remove empty optional fields per RFC 9728.
$metadata = $this->filterEmptyFields($metadata);
```

**Service Definition Update:**

In `simple_oauth_server_metadata.services.yml`, add event dispatcher argument:

```yaml
simple_oauth_server_metadata.resource_metadata:
  class: Drupal\simple_oauth_server_metadata\Service\ResourceMetadataService
  arguments:
    - '@config.factory'
    - '@simple_oauth_server_metadata.endpoint_discovery'
    - '@event_dispatcher'
```

## Input Dependencies

- Task 1: Event class and constants must exist
- Existing `ResourceMetadataService` code

## Output Artifacts

- Modified `src/Service/ResourceMetadataService.php`
- Modified `simple_oauth_server_metadata.services.yml`

## Implementation Notes

<details>
<summary>Detailed Implementation Guidance</summary>

### File Modifications

**File 1**: `web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/src/Service/ResourceMetadataService.php`

Changes needed:
1. Add use statement for `EventDispatcherInterface`
2. Add use statements for event classes:
   ```php
   use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
   use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
   use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
   ```
3. Add constructor parameter
4. Add event dispatch in `getResourceMetadata()` method

**File 2**: `web/modules/contrib/simple_oauth_21/modules/simple_oauth_server_metadata/simple_oauth_server_metadata.services.yml`

Add `@event_dispatcher` as third argument to `simple_oauth_server_metadata.resource_metadata` service.

### Testing Integration

After changes, verify service still works:

```bash
# Clear cache to rebuild service container
vendor/bin/drush cache:rebuild

# Test metadata endpoint still works
curl -s https://your-site/.well-known/oauth-protected-resource | jq .

# Verify no errors in logs
vendor/bin/drush watchdog:show --severity=Error
```

### Backwards Compatibility

CRITICAL: The event dispatch is additive only:
- If no event subscribers exist, behavior is unchanged
- Event gets metadata array after configured fields are added
- Event returns modified metadata which continues through existing filtering
- No breaking changes to public API

### Reference Implementations

Similar pattern in codebase:
- `ServerMetadataService` has similar structure (can be used as reference for service patterns)
- `IntrospectionExceptionSubscriber` shows event subscriber registration

</details>
