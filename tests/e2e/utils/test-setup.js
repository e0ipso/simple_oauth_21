/**
 * @file
 * Shared test setup utilities to avoid duplication and conflicts.
 */

const { execDrushInTestSite } = require('./drush-helper');

/**
 * Create a unique admin user for test isolation.
 *
 * @param {string} testName - Unique identifier for the test
 * @return {Object} User credentials
 */
async function createUniqueAdminUser(testName = 'default') {
  const timestamp = Date.now();
  const username = `admin_${testName}_${timestamp}`;
  const email = `${username}@example.com`;
  const password = 'admin123';

  // Create user with unique credentials
  await execDrushInTestSite(
    `user:create ${username} --mail="${email}" --password="${password}"`,
  );
  await execDrushInTestSite(`user:role:add administrator ${username}`);

  return {
    username,
    email,
    password,
  };
}

/**
 * Setup admin user and login in one step.
 *
 * @param {Object} page - Playwright page object
 * @param {string} testName - Unique identifier for the test
 * @return {Object} User credentials used
 */
async function setupUniqueAdminUser(page, testName = 'default') {
  // Enable proxy_block module first
  await execDrushInTestSite('pm:enable proxy_block -y');

  // Create unique user
  const user = await createUniqueAdminUser(testName);

  // Login
  await page.goto('/user/login');
  await page.fill('#edit-name', user.username);
  await page.fill('#edit-pass', user.password);
  await page.click('#edit-submit');

  return user;
}

/**
 * Clean up test user after test completion.
 *
 * @param {string} username - Username to delete
 */
async function cleanupTestUser(username) {
  try {
    await execDrushInTestSite(`user:delete ${username}`);
  } catch (error) {
    // Ignore errors if user doesn't exist
    console.log(`Could not delete user ${username}: ${error.message}`);
  }
}

module.exports = {
  createUniqueAdminUser,
  setupUniqueAdminUser,
  cleanupTestUser,
};
