/**
 * @file
 * Console error handling utilities for E2E tests.
 */

/**
 * Setup console error capturing for a test.
 * Returns a function to get captured errors.
 *
 * @param {Object} page - Playwright page object
 * @return {Function} Function to get captured console errors
 */
function setupConsoleErrorCapture(page) {
  const consoleErrors = [];

  // Capture console messages
  page.on('console', msg => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });

  // Capture uncaught exceptions
  page.on('pageerror', error => {
    consoleErrors.push(`Uncaught exception: ${error.message}`);
  });

  // Return function to access errors
  return () => [...consoleErrors]; // Return copy to prevent mutation
}

/**
 * Filter console errors for critical issues only.
 *
 * @param {Array} errors - Array of console error messages
 * @return {Array} Filtered critical errors
 */
function filterCriticalErrors(errors) {
  return errors.filter(
    error =>
      error.includes('Uncaught') ||
      error.includes('TypeError') ||
      error.includes('ReferenceError') ||
      error.includes('SyntaxError') ||
      error.includes('Failed to fetch') ||
      error.includes('Network request failed'),
  );
}

/**
 * Assert no critical console errors occurred.
 *
 * @param {Array} errors - Array of console error messages
 * @param {Array} allowedErrors - Array of error patterns to ignore
 */
function assertNoCriticalErrors(errors, allowedErrors = []) {
  const criticalErrors = filterCriticalErrors(errors);

  // Filter out allowed errors
  const filteredErrors = criticalErrors.filter(error => {
    return !allowedErrors.some(pattern => error.includes(pattern));
  });

  if (filteredErrors.length > 0) {
    throw new Error(
      `Critical console errors detected:\n${filteredErrors.join('\n')}`,
    );
  }
}

module.exports = {
  setupConsoleErrorCapture,
  filterCriticalErrors,
  assertNoCriticalErrors,
};
