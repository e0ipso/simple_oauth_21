const { test, expect } = require('@playwright/test');

/**
 * Trivial E2E test to validate Playwright infrastructure.
 *
 * This test ensures that:
 * - Playwright can launch browsers
 * - Basic navigation works
 * - Test reporting functions correctly
 * - CI/CD integration works
 */

test.describe('Playwright Infrastructure Validation', () => {
  test('should load a basic webpage', async ({ page }) => {
    // Navigate to the base URL (fallback to a reliable test endpoint)
    const baseUrl = process.env.DDEV_PRIMARY_URL || 'https://httpbin.org/html';
    await page.goto(baseUrl);

    // Wait for page to be fully loaded
    await page.waitForLoadState('networkidle');

    // Verify basic HTML structure exists
    await expect(page.locator('html')).toBeVisible();
    await expect(page.locator('body')).toBeVisible();

    // Verify page title is not empty
    const title = await page.title();
    expect(title).toBeTruthy();
    expect(title.length).toBeGreaterThan(0);
  });

  test('should verify browser capabilities', async ({ page, browserName }) => {
    // Test browser-specific functionality
    const userAgent = await page.evaluate(() => navigator.userAgent);
    expect(userAgent).toBeTruthy();

    // Verify browser name is detected correctly
    expect(['chromium', 'firefox', 'webkit']).toContain(browserName);

    // Test JavaScript execution
    const result = await page.evaluate(() => 2 + 2);
    expect(result).toBe(4);
  });

  test('should handle basic page interactions', async ({ page }) => {
    const baseUrl = process.env.DDEV_PRIMARY_URL || 'https://httpbin.org/html';
    await page.goto(baseUrl);

    // Test screenshot capability
    await page.screenshot({ path: 'test-results/trivial-test-screenshot.png' });

    // Test page content exists
    const bodyContent = await page.textContent('body');
    expect(bodyContent).toBeTruthy();

    // Test viewport
    const viewport = page.viewportSize();
    expect(viewport).toBeTruthy();
    expect(viewport.width).toBeGreaterThan(0);
    expect(viewport.height).toBeGreaterThan(0);
  });
});
