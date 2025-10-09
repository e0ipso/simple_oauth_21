---
id: 1
group: 'entity-refactoring'
dependencies: []
status: 'pending'
created: '2025-10-09'
skills:
  - drupal-backend
  - php
---

# Update DeviceCode Entity Field Definition

## Objective

Replace the serialized string_long scopes field with oauth2_scope_reference field type in the DeviceCode entity to align with simple_oauth patterns.

## Skills Required

- **drupal-backend**: Deep understanding of Drupal entity field definitions, field types, and cardinality
- **php**: Strong PHP skills for entity API manipulation and type definitions

## Acceptance Criteria

- [ ] DeviceCode entity uses `oauth2_scope_reference` field type for scopes field
- [ ] Field has `CARDINALITY_UNLIMITED` setting
- [ ] Display options match Oauth2Token's scopes field configuration
- [ ] All serialization-related methods removed from DeviceCode entity
- [ ] Private `$scopeEntities` property removed
- [ ] PHPStan analysis passes with no new errors

Use your internal Todo tool to track these and keep on track.

## Technical Requirements

**File to modify:** `modules/simple_oauth_device_flow/src/Entity/DeviceCode.php`

**Key changes:**

1. **Update field definition (lines 105-113):**
   - Change from `BaseFieldDefinition::create('string_long')` to `BaseFieldDefinition::create('oauth2_scope_reference')`
   - Add `->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)`
   - Update display options to match Oauth2Token pattern (lines 123-137 in Oauth2Token.php)

2. **Remove serialization methods:**
   - Delete `loadScopesFromDatabase()` method (lines 255-272)
   - Delete `saveScopesToDatabase()` method (lines 277-283)
   - Simplify `preSave()` method (lines 288-300) - remove serialization logic

3. **Simplify scope accessors:**
   - Update `getScopes()` (lines 151-156) to use `$this->get('scopes')->getScopes()`
   - Update `addScope()` (lines 161-164) to use field API: `$this->get('scopes')->appendItem(['scope_id' => $scope->getIdentifier()])`

4. **Remove private property:**
   - Delete `private array $scopeEntities = []` (line 49)

## Input Dependencies

- Review Oauth2Token entity implementation at `/var/www/html/web/modules/contrib/simple_oauth/src/Entity/Oauth2Token.php` (lines 123-137)
- Understand oauth2_scope_reference field type behavior from simple_oauth module

## Output Artifacts

- Modified DeviceCode.php with oauth2_scope_reference field
- Entity definition ready for schema update hooks
- Simplified entity code without custom serialization logic

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Update Field Definition

Replace the current field definition:

```php
$fields['scopes'] = BaseFieldDefinition::create('string_long')
  ->setLabel(t('Scopes'))
  ->setDescription(t('Serialized array of requested scopes.'))
  ->setTranslatable(FALSE)
  ->setDisplayOptions('view', [
    'label' => 'inline',
    'type' => 'string',
    'weight' => 4,
  ]);
```

With:

```php
$fields['scopes'] = BaseFieldDefinition::create('oauth2_scope_reference')
  ->setLabel(t('Scopes'))
  ->setDescription(t('The scopes for this Device Code.'))
  ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
  ->setTranslatable(FALSE)
  ->setDisplayOptions('view', [
    'label' => 'inline',
    'type' => 'oauth2_scope_reference_label',
    'weight' => 4,
  ])
  ->setDisplayOptions('form', [
    'type' => 'oauth2_scope_reference',
    'weight' => 4,
  ]);
```

### Step 2: Update getScopes() Method

Replace:

```php
public function getScopes(): array {
  if (empty($this->scopeEntities)) {
    $this->loadScopesFromDatabase();
  }
  return $this->scopeEntities;
}
```

With:

```php
public function getScopes(): array {
  return $this->get('scopes')->getScopes();
}
```

### Step 3: Update addScope() Method

Replace:

```php
public function addScope(ScopeEntityInterface $scope): void {
  $this->scopeEntities[] = $scope;
  $this->saveScopesToDatabase();
}
```

With:

```php
public function addScope(ScopeEntityInterface $scope): void {
  $this->get('scopes')->appendItem(['scope_id' => $scope->getIdentifier()]);
}
```

### Step 4: Simplify preSave()

Remove serialization logic from preSave() method. The method should only handle the created timestamp:

```php
public function preSave(EntityStorageInterface $storage) {
  parent::preSave($storage);

  // Set created timestamp if not set.
  if ($this->isNew() && !$this->get('created_at')->value) {
    $this->set('created_at', \Drupal::time()->getRequestTime());
  }
}
```

### Step 5: Remove Private Property

Delete line 49:

```php
private array $scopeEntities = [];
```

### Step 6: Delete Helper Methods

Remove the `loadScopesFromDatabase()` and `saveScopesToDatabase()` methods entirely.

### Important Considerations

- **MUST** use `declare(strict_types=1);` at the top of the file
- **MUST** use `final` keyword for the class (it's already a final implementation)
- **MUST** add proper PHPDoc comments for all modified methods
- Follow Drupal coding standards with proper spacing and indentation
- Import `FieldStorageDefinitionInterface` at the top of the file if not already present

</details>
