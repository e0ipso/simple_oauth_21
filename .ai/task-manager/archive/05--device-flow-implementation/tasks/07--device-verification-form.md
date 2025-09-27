---
id: 7
group: 'endpoints'
dependencies: [6]
status: 'pending'
created: '2025-09-26'
skills: ['drupal-backend', 'php']
---

# Implement Device Verification Form and Controller

## Objective

Create the device verification form and controller that allows users to enter user codes, authenticate, and authorize device requests at /oauth/device.

## Skills Required

- **drupal-backend**: Form API, user authentication, controllers
- **php**: Form handling, validation, OAuth integration

## Acceptance Criteria

- [ ] Form accepts user code input with validation
- [ ] Redirects to login if user not authenticated
- [ ] Associates device with authenticated user
- [ ] Displays success/error messages appropriately
- [ ] Calls grant's completeDeviceAuthorizationRequest
- [ ] Proper CSRF protection and security

## Technical Requirements

- Use Drupal Form API
- Integrate with user authentication system
- Validate user codes securely
- Handle device authorization completion
- Provide clear user feedback

## Input Dependencies

- Device authorization controller from task 6
- Grant plugin from task 5

## Output Artifacts

- src/Form/DeviceVerificationForm.php
- src/Controller/DeviceVerificationController.php
- User-facing verification interface

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Form structure:**

```php
class DeviceVerificationForm extends FormBase {

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['user_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enter the code shown on your device'),
      '#description' => $this->t('Enter the code exactly as shown (example: XXXX-XXXX)'),
      '#required' => TRUE,
      '#maxlength' => 32,
      '#pattern' => '[A-Z0-9-]+',
    ];

    $form['approve'] = [
      '#type' => 'submit',
      '#value' => $this->t('Authorize Device'),
      '#button_type' => 'primary',
    ];

    $form['deny'] = [
      '#type' => 'submit',
      '#value' => $this->t('Deny Access'),
      '#submit' => ['::denyAccess'],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $user_code = $form_state->getValue('user_code');

    // Normalize user code (remove spaces, convert to uppercase)
    $normalized_code = strtoupper(str_replace([' ', '-'], '', $user_code));

    // Load device code by user code
    $device_code = $this->deviceCodeRepository->getDeviceCodeEntityByUserCode($normalized_code);

    if (!$device_code) {
      $form_state->setErrorByName('user_code', $this->t('Invalid or expired code.'));
      return;
    }

    if ($device_code->getExpiryDateTime() < new \DateTimeImmutable()) {
      $form_state->setErrorByName('user_code', $this->t('This code has expired.'));
      return;
    }

    // Store for submission
    $form_state->setValue('device_code_entity', $device_code);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $device_code = $form_state->getValue('device_code_entity');
    $user = $this->currentUser();

    // Complete device authorization
    $grant = $this->grantManager->createInstance('device_code');
    $grant->completeDeviceAuthorizationRequest(
      $device_code->getIdentifier(),
      $user->id(),
      TRUE
    );

    $this->messenger()->addMessage($this->t('Device authorization successful. You may return to your device.'));
  }

  public function denyAccess(array &$form, FormStateInterface $form_state): void {
    $device_code = $form_state->getValue('device_code_entity');
    $user = $this->currentUser();

    $grant = $this->grantManager->createInstance('device_code');
    $grant->completeDeviceAuthorizationRequest(
      $device_code->getIdentifier(),
      $user->id(),
      FALSE
    );

    $this->messenger()->addMessage($this->t('Device authorization denied.'));
  }
}
```

**Controller for routing:**

```php
class DeviceVerificationController extends ControllerBase {

  public function form(): array {
    // Check if user is authenticated
    if ($this->currentUser()->isAnonymous()) {
      // Redirect to login with destination
      $url = Url::fromRoute('user.login', [], [
        'query' => ['destination' => Url::fromRoute('simple_oauth_device_flow.verify')->toString()],
      ]);
      return new RedirectResponse($url->toString());
    }

    return $this->formBuilder()->getForm(DeviceVerificationForm::class);
  }
}
```

**Security considerations:**

- Time-constant comparison for user codes
- Validate expiration times
- Ensure user is authenticated
- CSRF protection via Form API
- Rate limiting (handled by route)
</details>
