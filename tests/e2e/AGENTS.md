# Playwright End-to-End Testing Methodology

This file provides guidance to Claude Code when working with Playwright tests in this Drupal module.

## Testing Philosophy

**No Conditional Logic - Real Assertions Only**

- Never skip assertions or use conditional logic to mask failures
- Tests must fail when functionality is broken
- Always debug and fix root causes, not symptoms

## Drupal-Specific Testing Patterns

### Page State Management

**Critical**: Always wait for complete page state after navigation and form submissions.

```javascript
// Required pattern for all form interactions
await page.click('button:has-text("Save")');
await page.waitForLoadState('networkidle'); // Wait for AJAX/redirects
await page.waitForTimeout(1000); // Additional buffer for DOM updates
```

### [Specific Drupal Sub-system 1]

[Update this to your needs]

### [Specific Drupal Sub-system 2]

[Update this to your needs]

### [Specific Drupal-ism]

[Update this to your needs]

## Robust Testing Strategies

### 1. Selector Resilience

Use multiple selector strategies for critical elements:

```javascript
// Multi-strategy selectors
const titleField = page
  .locator('#edit-settings-label, input[type="text"][name*="label"]')
  .first();
```

### 2. Error State Handling

Always check for error conditions:

```javascript
// Verify no PHP errors on page
const phpErrors = page.locator(
  '.php-error, .error-message:has-text("Fatal"), .messages--error:has-text("Fatal")',
);
await expect(phpErrors).toHaveCount(0);
```

### 3. [Additional strategy]

[Update this to your needs]

## Common Drupal Pitfalls

### 1. Region Requirements

- **All blocks MUST have a region assigned during creation**
- Missing regions cause silent failures
- Blocks without regions don't appear in layout or frontend

### 2. Cache Invalidation

- Form submissions may require cache clearing
- Use `page.waitForLoadState('networkidle')` after saves
- Consider explicit cache clearing for complex workflows

### 3. AJAX Handling

- Many Drupal forms use AJAX for dynamic updates
- Always wait for network idle after form interactions
- Look for loading indicators and wait for them to disappear

### 4. Theme-Specific Elements

- Selectors may vary between themes
- Use theme-agnostic selectors when possible
- Test with the actual theme used in production

## Debugging Strategies

### 1. Screenshot Analysis

Always capture screenshots on failure:

```javascript
test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status === 'failed') {
    await page.screenshot({
      path: `debug-${testInfo.title}-${Date.now()}.png`,
      fullPage: true,
    });
  }
});
```

### 2. DOM State Logging

Log page state when selectors fail:

```javascript
try {
  await expect(element).toBeVisible();
} catch (error) {
  console.log('Current URL:', page.url());
  console.log('Page title:', await page.title());
  const bodyText = await page.locator('body').textContent();
  console.log('Page contains:', bodyText.substring(0, 500));
  throw error;
}
```

### 3. Network Monitoring

Monitor AJAX requests for debugging:

```javascript
page.on('response', response => {
  if (response.status() >= 400) {
    console.log(`HTTP Error: ${response.status()} ${response.url()}`);
  }
});
```

## Test Structure Template

```javascript
test.describe('Module Feature Tests', () => {
  const testId = Date.now(); // Unique identifier

  test.beforeEach(async ({ page }) => {
    // Login and setup
    await loginAsAdmin(page);
  });

  test.afterEach(async ({ page }) => {
    // Cleanup created resources
    // Log warnings but don't fail tests for cleanup issues
  });

  test('should perform complete workflow', async ({ page }) => {
    // 1. Setup
    const uniqueName = `Test Item ${testId}`;

    // 2. Action
    await performAction(page, uniqueName);

    // 3. Verification
    await verifyResult(page, uniqueName);

    // 4. Cleanup (if needed for this specific test)
  });
});
```

## Performance Considerations

- Use `test.setTimeout()` for complex workflows
- Prefer direct API calls over UI interactions when possible
- Run tests in parallel when they don't interfere with each other
- Use `page.waitForLoadState('networkidle')` judiciously (expensive)
