---
id: 4
group: 'form-standardization'
dependencies: [2, 3]
status: 'pending'
created: 2025-10-07
skills:
  - drupal-backend
  - php
---

# Update ConsumerNativeAppsFormAlter and Remove ConfigStructureMapper Dependency

## Objective

Remove the ConfigStructureMapper dependency from ConsumerNativeAppsFormAlter and update validation logic to work directly with nested configuration structure, demonstrating end-to-end simplification.

## Skills Required

- **drupal-backend**: Understanding of Drupal form alter hooks, dependency injection, and service definitions
- **php**: Ability to refactor service dependencies and validation logic

## Acceptance Criteria

- [ ] ConfigStructureMapper removed from constructor and property
- [ ] ConfigStructureMapper removed from service definition (simple_oauth_native_apps.services.yml)
- [ ] Validation builds nested config directly without mapper
- [ ] Form element references updated if needed
- [ ] AJAX callbacks work with nested structure
- [ ] No import statements or references to ConfigStructureMapper remain

## Technical Requirements

**Files to modify**:

1. `src/Form/ConsumerNativeAppsFormAlter.php`
2. `simple_oauth_native_apps.services.yml`

**Major changes**:

- Remove ConfigStructureMapper from constructor (line 70)
- Remove ConfigStructureMapper property (line 54)
- Update validateConsumerNativeAppsSettings (lines 256-287)
- Remove mapper call (line 281)

## Input Dependencies

- Task 2: ConfigurationValidator accepts nested structure
- Task 3: Pattern established by NativeAppsSettingsForm refactor

## Output Artifacts

- Consumer form alter working without structure mapper
- Proof that nested structure works across all form operations
- Service definition without mapper dependency

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Phase 1: Remove ConfigStructureMapper Dependency

1. **Update constructor** (lines 70-82):

   ```php
   // BEFORE
   public function __construct(
     ConfigFactoryInterface $config_factory,
     EntityTypeManagerInterface $entity_type_manager,
     ConfigurationValidator $configuration_validator,
     NativeClientDetector $client_detector,
     ConfigStructureMapper $config_mapper,  // REMOVE THIS
   ) {
     // ...
     $this->configMapper = $config_mapper;  // REMOVE THIS
   }

   // AFTER
   public function __construct(
     ConfigFactoryInterface $config_factory,
     EntityTypeManagerInterface $entity_type_manager,
     ConfigurationValidator $configuration_validator,
     NativeClientDetector $client_detector,
   ) {
     $this->configFactory = $config_factory;
     $this->entityTypeManager = $entity_type_manager;
     $this->configurationValidator = $configuration_validator;
     $this->clientDetector = $client_detector;
   }
   ```

2. **Remove property declaration** (line 54):

   ```php
   // DELETE THIS
   protected ConfigStructureMapper $configMapper;
   ```

3. **Remove use statement** (around line 12):
   ```php
   // DELETE THIS
   use Drupal\simple_oauth_native_apps\Service\ConfigStructureMapper;
   ```

### Phase 2: Update Service Definition

**File**: `simple_oauth_native_apps.services.yml`

Find the service definition for `simple_oauth_native_apps.consumer_form_alter` and remove the ConfigStructureMapper argument:

```yaml
# BEFORE
simple_oauth_native_apps.consumer_form_alter:
  class: Drupal\simple_oauth_native_apps\Form\ConsumerNativeAppsFormAlter
  arguments:
    - '@config.factory'
    - '@entity_type.manager'
    - '@simple_oauth_native_apps.configuration_validator'
    - '@simple_oauth_native_apps.native_client_detector'
    - '@simple_oauth_native_apps.config_structure_mapper'  # REMOVE THIS LINE

# AFTER
simple_oauth_native_apps.consumer_form_alter:
  class: Drupal\simple_oauth_native_apps\Form\ConsumerNativeAppsFormAlter
  arguments:
    - '@config.factory'
    - '@entity_type.manager'
    - '@simple_oauth_native_apps.configuration_validator'
    - '@simple_oauth_native_apps.native_client_detector'
```

### Phase 3: Update Validation Logic (lines 256-287)

Replace structure mapping with direct nested structure building:

```php
public function validateConsumerNativeAppsSettings(array $form, FormStateInterface $form_state): void {
  $values = $form_state->getValue('native_apps', []);

  // Build configuration in nested structure directly - NO MAPPING NEEDED
  $validator_config = [];

  // Only include non-empty overrides in nested structure
  if (!empty($values['webview_detection_override'])) {
    $validator_config['webview']['detection'] = $values['webview_detection_override'];
  }

  if ($values['allow_custom_schemes_override'] !== '') {
    $validator_config['allow']['custom_uri_schemes'] = $values['allow_custom_schemes_override'];
  }

  if ($values['allow_loopback_override'] !== '') {
    $validator_config['allow']['loopback_redirects'] = $values['allow_loopback_override'];
  }

  if ($values['enhanced_pkce_override'] !== '') {
    $validator_config['native']['enhanced_pkce'] = $values['enhanced_pkce_override'];
  }

  // Validate directly - no mapper needed
  if (!empty($validator_config)) {
    $errors = $this->configurationValidator->validateConfiguration($validator_config);
    foreach ($errors as $error) {
      $form_state->setErrorByName('native_apps', $error);
    }
  }
}
```

### Phase 4: Verify AJAX Callbacks

Check `detectClientTypeAjax` method (lines 417-436) and related methods:

- These read form values and may need updates if they reference field names
- Verify they work with nested form structure
- Test AJAX functionality manually

### Phase 5: Clear Cache and Test

```bash
# Clear cache to reload service definitions
vendor/bin/drush cache:rebuild

# Run static analysis
vendor/bin/phpstan analyse src/Form/ConsumerNativeAppsFormAlter.php

# Manual testing:
# 1. Edit a consumer entity
# 2. Verify Native App Settings section renders
# 3. Set overrides and save
# 4. Verify validation works
# 5. Test AJAX "Detect Client Type" button
```

### Phase 6: Verify No References Remain

```bash
# Search for any remaining references
grep -r "ConfigStructureMapper" src/
grep -r "config_structure_mapper" *.yml

# Should return NO results after this task
```

</details>
