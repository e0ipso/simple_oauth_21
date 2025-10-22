---
id: 2
group: 'configuration'
dependencies: [1]
status: 'completed'
created: 2025-10-22
skills:
  - drupal-backend
---

# Implement Settings Form for DCR Configuration

## Objective

Create a Drupal settings form (`ClientRegistrationSettingsForm`) that allows administrators to configure the `auto_enable_refresh_token` setting via a checkbox with clear help text explaining RFC 7591 compliance and OAuth 2.1 rationale.

## Skills Required

- `drupal-backend`: Drupal Form API, ConfigFormBase, form building, validation

## Acceptance Criteria

- [ ] Settings form class created at `src/Form/ClientRegistrationSettingsForm.php`
- [ ] Form extends `ConfigFormBase` and follows Drupal standards
- [ ] Single checkbox control for `auto_enable_refresh_token` setting
- [ ] Help text explains RFC 7591 authority and OAuth 2.1 best practices
- [ ] Help text clarifies that explicit client grant_types override defaults
- [ ] Help text notes scope is DCR-only (not admin UI client creation)
- [ ] Code follows project standards: `declare(strict_types=1)`, `final` class, typed properties, comprehensive PHPDoc
- [ ] Form saves and loads configuration correctly

## Technical Requirements

**Module**: `simple_oauth_client_registration`

**File to Create**: `modules/simple_oauth_client_registration/src/Form/ClientRegistrationSettingsForm.php`

**Class Structure**:

- Extends: `\Drupal\Core\Form\ConfigFormBase`
- Namespace: `Drupal\simple_oauth_client_registration\Form`
- Class modifier: `final`
- Strict types declaration: Required

**Form Elements**:

- Checkbox: `auto_enable_refresh_token`
- Label: "Automatically enable refresh_token grant for dynamically registered clients"
- Description: Multi-line help text covering RFC compliance and scope

## Input Dependencies

- Task 1: Configuration schema must exist for form to save/load config

## Output Artifacts

- `ClientRegistrationSettingsForm.php`: Drupal configuration form
- Form accessible once routing is added (Task 3)
- Enables administrators to toggle the feature on/off

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Create Form Class

File: `src/Form/ClientRegistrationSettingsForm.php`

```php
<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Client Registration settings.
 */
final class ClientRegistrationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_oauth_client_registration_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_oauth_client_registration.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('simple_oauth_client_registration.settings');

    $form['auto_enable_refresh_token'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically enable refresh_token grant for dynamically registered clients'),
      '#description' => $this->t('When enabled, clients registering via the Dynamic Client Registration (DCR) endpoint without explicitly specifying grant types will automatically receive both <code>authorization_code</code> and <code>refresh_token</code> grants. This aligns with OAuth 2.1 best practices for native applications and public clients using PKCE.<br><br><strong>RFC 7591 Compliance:</strong> Section 3.2.1 grants authorization servers the authority to modify client metadata and provision defaults.<br><br><strong>Scope:</strong> This setting only affects the <code>POST /oauth/register</code> endpoint. Manual client creation via the admin UI is unaffected. Clients that explicitly specify grant types in their registration request will always have their choices respected.'),
      '#default_value' => $config->get('auto_enable_refresh_token') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('simple_oauth_client_registration.settings')
      ->set('auto_enable_refresh_token', $form_state->getValue('auto_enable_refresh_token'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
```

### Key Implementation Points

- **declare(strict_types=1)**: Required at top of file
- **final class**: Prevents inheritance per project standards
- **Return types**: All methods have explicit return type declarations
- **PHPDoc**: Class-level documentation required
- **Config handling**: Use `$this->config()` to load, `->save()` to persist
- **Default value**: Use null coalescing `?? TRUE` to handle missing config
- **Help text**: Use `$this->t()` for translatability
- **HTML in description**: Use `<code>` and `<br>` for formatting

### Coding Standards Compliance

- No trailing spaces
- Newline at end of file
- Proper indentation (2 spaces per Drupal standards)
- Comprehensive PHPDoc comments

</details>
