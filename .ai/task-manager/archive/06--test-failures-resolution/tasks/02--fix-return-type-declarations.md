---
id: 2
group: 'deprecation-fixes'
dependencies: []
status: 'completed'
created: '2025-09-27'
skills: ['php', 'drupal-backend']
---

# Fix Return Type Declarations

## Objective

Add missing return type declarations to eliminate critical deprecation warnings in EventSubscriber and UserIdentityProvider classes.

## Skills Required

PHP development and Drupal module architecture knowledge to properly implement return types.

## Acceptance Criteria

- [ ] Add `array` return type to `ExceptionLoggingSubscriber::getSubscribedEvents()`
- [ ] Add proper return type to `UserIdentityProvider::getUserEntityByIdentifier()`
- [ ] Fix `void` return type in `Oauth2RedirectUriValidator::validate()`
- [ ] All deprecation warnings for return types eliminated

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

- PHP 8.3 return type syntax
- Symfony EventSubscriber interface compatibility
- OpenID Connect Server interface requirements

## Input Dependencies

None - can be done in parallel with task 1.

## Output Artifacts

- Updated PHP classes with proper return type declarations
- Reduced deprecation count in test output

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

1. **Fix ExceptionLoggingSubscriber** (`/var/www/html/web/modules/contrib/simple_oauth/src/EventSubscriber/ExceptionLoggingSubscriber.php`):
   - Locate the `getSubscribedEvents()` method
   - Add `: array` return type declaration
   - Example: `public static function getSubscribedEvents(): array`

2. **Fix UserIdentityProvider** (`/var/www/html/web/modules/contrib/simple_oauth/src/OpenIdConnect/UserIdentityProvider.php`):
   - Find `getUserEntityByIdentifier()` method
   - The deprecation indicates it should return `UserEntityInterface&ClaimSetInterface`
   - Since PHP doesn't support intersection types in return declarations directly, add proper @return annotation:
     ```php
     /**
      * @return \OpenIDConnectServer\Entities\UserEntityInterface&\OpenIDConnectServer\Entities\ClaimSetInterface
      */
     ```
   - Or use a union type if appropriate for the PHP version

3. **Fix Oauth2RedirectUriValidator** (`/var/www/html/web/modules/contrib/simple_oauth/src/Plugin/Validation/Constraint/Oauth2RedirectUriValidator.php`):
   - Find the `validate()` method
   - Add `: void` return type
   - Example: `public function validate($value, Constraint $constraint): void`

4. **Fix entity property access** (if found):
   - Search for uses of `->original` property access
   - Replace with `->getOriginal()` method calls

5. **Verification**:
   - Run tests to ensure no new failures
   - Check deprecation count has decreased
   </details>
