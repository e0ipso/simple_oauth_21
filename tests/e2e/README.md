# End-to-End (E2E) Tests

This directory contains Playwright-based end-to-end tests for the Proxy Block module, providing comprehensive browser-based testing of the module's functionality within a complete Drupal environment.

## Test Architecture

The E2E test suite is organized using the Page Object Model design pattern for maintainability and reusability:

```
tests/e2e/
├── page-objects/           # Reusable page interaction objects
│   ├── block-placement-page.js   # Block placement UI interactions
│   └── frontend-page.js           # Frontend page validation
├── tests/                  # Test specification files
│   ├── auth-simple.spec.js        # Basic authentication tests
│   ├── auth.spec.js               # Complete authentication workflow
│   ├── block-placement.spec.js    # Block placement functionality
│   ├── ci-basic.spec.js           # CI-compatible infrastructure tests
│   ├── login-debug.spec.js        # Login debugging and troubleshooting
│   ├── proxy-block-basic.spec.js  # Core proxy block functionality
│   ├── render.spec.js             # Block rendering validation
│   └── simple.spec.js             # Infrastructure validation
└── utils/                  # Helper utilities and test setup
    ├── ajax-helper.js             # AJAX waiting and interaction helpers
    ├── console-helper.js          # Console error capture and filtering
    ├── constants.js               # Test configuration constants
    ├── drush-helper.js            # Drupal command-line utilities
    ├── test-setup.js              # Test environment setup
    └── theme-helper.js            # Drupal theme switching utilities
```

## Test Categories

### Infrastructure Tests

**Files**: `ci-basic.spec.js`, `simple.spec.js`

**Purpose**: Validate basic site functionality and infrastructure readiness without requiring complex Drupal configurations.

- **Site Accessibility**: Verify Drupal site responds with valid HTTP status codes
- **Page Structure**: Confirm basic HTML structure exists
- **Admin Area Access**: Test admin page responses (redirects, access denied, etc.)
- **Screenshot Capture**: Generate visual verification artifacts
- **Error Handling**: Graceful handling of PHP errors and JavaScript console issues

**Environment Requirements**: Minimal - works with any accessible Drupal installation.

### Authentication Tests

**Files**: `auth-simple.spec.js`, `auth.spec.js`, `login-debug.spec.js`

**Purpose**: Provide authentication helpers for proxy block testing without validating Drupal core functionality.

- **Authentication Helpers**: Utilities for logging in/out for test setup
- **Debug Utilities**: Troubleshoot authentication issues in different environments

**Environment Requirements**: Drupal site with admin user (username: `admin`, password: `admin`).

### Block Management Tests

**Files**: `block-placement.spec.js`, `proxy-block-basic.spec.js`

**Purpose**: Test the core proxy block functionality focusing on module-specific features.

- **Configuration UI**: Validate proxy block configuration form and unique settings
- **Target Block Selection**: Test dropdown functionality and AJAX updates specific to proxy blocks
- **Context Mapping**: Verify context passing to target blocks (key proxy block functionality)
- **Proxy-Specific Logic**: Test edge cases and error handling unique to proxy blocks

**Environment Requirements**: Drupal site with proxy_block module enabled and admin access.

### Rendering Tests

**Files**: `render.spec.js`

**Purpose**: Validate proxy block rendering functionality and target block integration.

- **Frontend Display**: Test proxy block output renders target block content correctly
- **Content Validation**: Verify target block content appears within proxy block wrapper
- **Edge Cases**: Test rendering with different target blocks, contexts, and configurations

**Environment Requirements**: Complete Drupal site with content and proxy blocks configured.

## Test Utilities

### Page Objects

**`block-placement-page.js`**: Encapsulates interactions with Drupal's block placement interface, including navigation to block admin pages, searching for blocks, and configuring block settings.

**`frontend-page.js`**: Handles frontend page validation, screenshot capture, accessibility checking, and content verification.

### Helper Utilities

**`drush-helper.js`**: Provides Drupal command-line operations for test setup, including user creation, module enabling, and cache clearing.

**`ajax-helper.js`**: Manages AJAX interactions within Drupal admin interface, replacing fixed timeouts with proper wait conditions.

**`console-helper.js`**: Captures and filters browser console errors, distinguishing between critical errors and expected warnings.

**`test-setup.js`**: Standardizes test environment preparation, including database isolation and cleanup procedures.

**`theme-helper.js`**: Utilities for switching Drupal themes during testing to ensure consistent UI behavior.

### Potential Upstream Contributions

The following utilities could be valuable additions to `@lullabot/playwright-drupal`:

**General Drupal Utilities** (upstream candidates):

- `ajax-helper.js`: AJAX waiting patterns for Drupal admin interfaces
- `console-helper.js`: Browser console error filtering and categorization
- `drush-helper.js`: Drupal command-line utilities for test environments

**Module-Specific Utilities** (keep in proxy_block):

- Block placement page objects and proxy block configuration helpers
- Proxy block specific test constants and validation utilities

## Running Tests

### Local Development

```bash
# Install dependencies and browsers
npm ci
npm run e2e:install

# Run all tests
npm run e2e:test

# Run with browser UI (for debugging)
npm run e2e:test:headed

# Run in debug mode with step-by-step execution
npm run e2e:test:debug

# Run specific test files
npx playwright test tests/e2e/tests/ci-basic.spec.js
npx playwright test tests/e2e/tests/auth-simple.spec.js
```

### Continuous Integration

The tests are configured to run automatically in GitHub Actions with two different strategies:

1. **Infrastructure Tests** (`ci-basic.spec.js`): Always run with basic Drupal setup
2. **Advanced Tests**: Run with full Drupal configuration but continue on error

## Test Configuration

Tests automatically detect the environment and configure accordingly:

- **CI Environment**: Uses `DRUPAL_BASE_URL` or defaults to `http://127.0.0.1:8080`
- **Custom Setup**: Set `DRUPAL_BASE_URL` environment variable

## Debugging and Troubleshooting

### Common Issues

**Tests Timeout**: Increase timeout in playwright.config.js or use environment-specific settings.

**Authentication Failures**: Verify admin user exists and has correct credentials using `login-debug.spec.js`.

**Module Not Found**: Ensure proxy_block module is enabled in the test environment.

**AJAX Interactions Fail**: Check console errors and use `ajax-helper.js` utilities instead of fixed timeouts.

### Debug Tools

- **Screenshots**: Automatically captured on test failures
- **Videos**: Recorded for failed test runs
- **Console Logs**: Captured and filtered for relevant errors
- **Trace Files**: Detailed execution traces available for complex debugging

### Test Reports

```bash
# View the last test report
npm run e2e:report

# Test artifacts are saved to:
# - playwright-report/ (HTML report)
# - test-results/ (screenshots, videos, traces)
```

## Writing New Tests

When adding new E2E tests:

1. **Use Page Objects**: Leverage existing page objects or create new ones for reusable functionality
2. **Follow Naming Convention**: Use descriptive file names ending in `.spec.js`
3. **Add Error Handling**: Use helper utilities for robust error handling and waiting
4. **Document Purpose**: Include clear test descriptions and comments
5. **Test Isolation**: Ensure tests can run independently and clean up after themselves

Example test structure:

```javascript
const { test, expect } = require('@playwright/test');
const { BlockPlacementPage } = require('../page-objects/block-placement-page');

test.describe('My New Feature Tests', () => {
  test('should do something specific', async ({ page }) => {
    const blockPlacement = new BlockPlacementPage(page);

    // Test implementation
    await blockPlacement.navigateToBlockAdmin();
    // ... test steps

    // Assertions
    await expect(page.locator('.result')).toBeVisible();
  });
});
```

## Integration with CI/CD

The E2E tests are integrated into the module's CI/CD pipeline:

- **Pull Requests**: Core infrastructure tests run on every PR
- **Main Branch**: Full test suite runs on merges to main
- **Scheduled**: Complete test suite runs daily to catch environment drift
- **Artifacts**: Test reports, screenshots, and videos are preserved for debugging

This comprehensive E2E testing framework ensures the Proxy Block module works reliably across different Drupal environments and configurations.
