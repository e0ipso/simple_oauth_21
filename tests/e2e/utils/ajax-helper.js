/**
 * @file
 * AJAX utilities for E2E tests.
 */

/**
 * Wait for AJAX operations to complete.
 * This is a more robust alternative to fixed timeouts.
 *
 * @param {Object} page - The Playwright page object
 * @param {number} timeout - Maximum timeout in milliseconds
 */
async function waitForAjax(page, timeout = 30000) {
  // Wait for any AJAX throbbers to disappear
  await page.waitForFunction(
    () => {
      const throbbers = document.querySelectorAll(
        '.ajax-progress-throbber, .ajax-progress-bar',
      );
      return throbbers.length === 0;
    },
    { timeout },
  );

  // Wait for network to be idle
  await page.waitForLoadState('networkidle');
}

/**
 * Wait for dynamic content to load without arbitrary timeouts.
 * This replaces the problematic waitForTimeout patterns.
 *
 * @param {Object} page - The Playwright page object
 * @param {number} timeout - Maximum timeout in milliseconds
 */
async function waitForDynamicContent(page, timeout = 10000) {
  // Wait for any AJAX or dynamic content
  await page.waitForFunction(() => document.readyState === 'complete', {
    timeout,
  });

  // Wait for network idle state instead of arbitrary timeout
  await page.waitForLoadState('networkidle', { timeout: 5000 });
}

module.exports = {
  waitForAjax,
  waitForDynamicContent,
};
