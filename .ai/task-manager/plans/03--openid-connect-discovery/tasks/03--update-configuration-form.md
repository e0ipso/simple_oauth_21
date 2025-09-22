---
id: 3
group: 'configuration'
dependencies: []
status: 'pending'
created: '2025-01-22'
skills: ['drupal-backend', 'forms']
complexity_score: 2.0
complexity_notes: 'Simple form extension following existing patterns'
---

# Update Configuration Form for OpenID Connect Discovery Settings

## Objective

Extend the existing ServerMetadataSettingsForm to include configuration options for the OpenID Connect Discovery endpoint, allowing administrators to configure optional metadata fields.

## Skills Required

- **drupal-backend**: Drupal form API, configuration management, and form validation
- **forms**: Form element creation, validation logic, and user interface design

## Acceptance Criteria

- [ ] ServerMetadataSettingsForm extended with OpenID Connect Discovery settings
- [ ] Configuration fields for optional metadata values
- [ ] Form validation for required fields
- [ ] Configuration schema updated for new settings
- [ ] Default configuration values provided
- [ ] Form follows Drupal UI patterns and coding standards
- [ ] Proper help text and descriptions for all fields

## Technical Requirements

**Configuration Fields to Add:**

- Service documentation URL field
- Supported response types configuration
- Supported response modes configuration
- Additional optional metadata fields as needed

**Schema Requirements:**

- Update `config/schema/simple_oauth_server_metadata.schema.yml`
- Add default values in `config/install/simple_oauth_server_metadata.settings.yml`
- Proper data types and validation rules

## Input Dependencies

None - this configuration form can be implemented independently

## Output Artifacts

- Updated `src/Form/ServerMetadataSettingsForm.php` - Extended form class
- Updated `config/schema/simple_oauth_server_metadata.schema.yml` - Configuration schema
- Updated `config/install/simple_oauth_server_metadata.settings.yml` - Default configuration

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

### Form Extension

Extend the existing `buildForm()` method in `ServerMetadataSettingsForm`:

```php
public function buildForm(array $form, FormStateInterface $form_state): array {
  $form = parent::buildForm($form, $form_state);
  $config = $this->config('simple_oauth_server_metadata.settings');

  // Add OpenID Connect Discovery section
  $form['openid_discovery'] = [
    '#type' => 'details',
    '#title' => $this->t('OpenID Connect Discovery'),
    '#description' => $this->t('Configuration for the OpenID Connect Discovery endpoint at /.well-known/openid-configuration'),
    '#open' => TRUE,
  ];


  $form['openid_discovery']['service_documentation'] = [
    '#type' => 'url',
    '#title' => $this->t('Service Documentation URL'),
    '#description' => $this->t('URL to documentation about the OpenID Connect implementation.'),
    '#default_value' => $config->get('service_documentation') ?? 'https://www.drupal.org/project/simple_oauth',
  ];

  $form['openid_discovery']['response_types_supported'] = [
    '#type' => 'checkboxes',
    '#title' => $this->t('Supported Response Types'),
    '#description' => $this->t('Select the OAuth 2.0 response types that are supported.'),
    '#options' => [
      'code' => 'code',
      'token' => 'token',
      'id_token' => 'id_token',
      'code id_token' => 'code id_token',
    ],
    '#default_value' => $config->get('response_types_supported') ?? ['code', 'id_token', 'code id_token'],
  ];

  $form['openid_discovery']['response_modes_supported'] = [
    '#type' => 'checkboxes',
    '#title' => $this->t('Supported Response Modes'),
    '#description' => $this->t('Select the OAuth 2.0 response modes that are supported.'),
    '#options' => [
      'query' => 'query',
      'fragment' => 'fragment',
    ],
    '#default_value' => $config->get('response_modes_supported') ?? ['query', 'fragment'],
  ];

  return $form;
}
```

### Form Validation

Add validation in `validateForm()`:

```php
public function validateForm(array &$form, FormStateInterface $form_state): void {
  parent::validateForm($form, $form_state);

  // Validate at least one response type is selected
  $response_types = array_filter($form_state->getValue('response_types_supported'));
  if (empty($response_types)) {
    $form_state->setErrorByName('response_types_supported', $this->t('At least one response type must be selected.'));
  }
}
```

### Configuration Schema Update

Add to `config/schema/simple_oauth_server_metadata.schema.yml`:

```yaml
simple_oauth_server_metadata.settings:
  type: config_object
  label: 'Simple OAuth Server Metadata settings'
  mapping:
    # ... existing fields ...
    service_documentation:
      type: string
      label: 'Service Documentation URL'
    response_types_supported:
      type: sequence
      label: 'Supported Response Types'
      sequence:
        type: string
    response_modes_supported:
      type: sequence
      label: 'Supported Response Modes'
      sequence:
        type: string
```

### Default Configuration

Update `config/install/simple_oauth_server_metadata.settings.yml`:

```yaml
# ... existing settings ...
service_documentation: 'https://www.drupal.org/project/simple_oauth'
response_types_supported:
  - 'code'
  - 'id_token'
  - 'code id_token'
response_modes_supported:
  - 'query'
  - 'fragment'
```

### Key Implementation Points

- Provide sensible default values
- Add proper validation for required fields
- Follow existing form patterns in the module
- Include helpful descriptions for all fields
- Use proper Drupal form API elements
- The endpoint is always available when the module is enabled
</details>
