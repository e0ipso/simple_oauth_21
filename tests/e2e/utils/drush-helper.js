/**
 * @file
 * Custom Drush execution utilities for E2E tests.
 *
 * Replaces problematic @lullabot/playwright-drupal imports with
 * standard Node.js child_process execution patterns.
 */

const { execSync } = require('child_process');
const path = require('path');

/**
 * Execute Drush commands in the Drupal site.
 *
 * @param {string} command - Drush command to execute (without 'drush' prefix)
 * @param {Object} options - Execution options
 * @return {string} Command output
 */
async function execDrushInTestSite(command, options = {}) {
  // Use relative path from this file to the Drupal root
  // tests/e2e/utils -> ../../../../.. gets us to the main Drupal root
  const drupalRoot = path.resolve(__dirname, '../../../../../../..');

  const defaults = {
    cwd: drupalRoot,
    encoding: 'utf8',
    timeout: 30000, // 30 second timeout
    stdio: 'pipe',
    ...options,
  };

  try {
    // Use drush with database access
    const drushCommand = `vendor/bin/drush ${command}`;

    // Execute synchronously to match the expected API
    const result = execSync(drushCommand, defaults);

    // Return trimmed output as string
    return result.toString().trim();
  } catch (error) {
    // Log error details for debugging
    console.error(`Drush command failed: ${command}`);
    console.error(`Error: ${error.message}`);
    console.error(`Working directory: ${drupalRoot}`);

    // Re-throw with more context
    throw new Error(`Drush execution failed: ${command} - ${error.message}`);
  }
}

/**
 * Execute Drush commands asynchronously (for compatibility).
 *
 * @param {string} command - Drush command to execute
 * @param {Object} options - Execution options
 * @return {Promise<string>} Command output
 */
async function execDrushAsync(command, options = {}) {
  return new Promise((resolve, reject) => {
    try {
      const result = execDrushInTestSite(command, options);
      resolve(result);
    } catch (error) {
      reject(error);
    }
  });
}

/**
 * Check if Drush is available and working.
 *
 * @return {boolean} Whether Drush is available
 */
async function isDrushAvailable() {
  try {
    await execDrushInTestSite('status --format=json');
    return true;
  } catch (error) {
    console.warn('Drush not available:', error.message);
    return false;
  }
}

/**
 * Get Drupal site status information.
 *
 * @return {Object} Site status information
 */
async function getSiteStatus() {
  try {
    const result = await execDrushInTestSite('status --format=json');
    return JSON.parse(result);
  } catch (error) {
    console.warn('Could not get site status:', error.message);
    return {};
  }
}

/**
 * Enable a module if it's not already enabled.
 *
 * @param {string} moduleName - Module machine name
 * @return {boolean} Whether the module was enabled successfully
 */
async function enableModule(moduleName) {
  try {
    await execDrushInTestSite(`pm:enable ${moduleName} -y`);
    return true;
  } catch (error) {
    console.error(`Failed to enable module ${moduleName}:`, error.message);
    return false;
  }
}

/**
 * Clear all Drupal caches.
 *
 * @return {boolean} Whether cache clearing succeeded
 */
async function clearCache() {
  try {
    await execDrushInTestSite('cache:rebuild');
    return true;
  } catch (error) {
    console.error('Failed to clear cache:', error.message);
    return false;
  }
}

/**
 * Create an admin user for testing.
 *
 * @param {string} username - Username (defaults to 'admin')
 * @param {string} password - Password (defaults to 'admin')
 * @param {string} email - Email (defaults to admin@example.com)
 * @return {Object} User credentials
 */
async function createAdminUser(
  username = 'admin',
  password = 'admin',
  email = 'admin@example.com',
) {
  try {
    // Try to create user (might already exist)
    try {
      await execDrushInTestSite(
        `user:create ${username} --mail="${email}" --password="${password}"`,
      );
    } catch (createError) {
      // User might already exist, try to reset password instead
      await execDrushInTestSite(`user:password ${username} "${password}"`);
    }

    // Ensure user has admin role
    await execDrushInTestSite(`user:role:add administrator ${username}`);

    return {
      username,
      password,
      email,
    };
  } catch (error) {
    console.error(
      `Failed to create/setup admin user ${username}:`,
      error.message,
    );
    return {
      username,
      password,
      email,
    };
  }
}

/**
 * Delete a user account.
 *
 * @param {string} username - Username to delete
 * @return {boolean} Whether deletion succeeded
 */
async function deleteUser(username) {
  try {
    await execDrushInTestSite(`user:delete ${username}`);
    return true;
  } catch (error) {
    console.error(`Failed to delete user ${username}:`, error.message);
    return false;
  }
}

module.exports = {
  execDrushInTestSite,
  execDrushAsync,
  isDrushAvailable,
  getSiteStatus,
  enableModule,
  clearCache,
  createAdminUser,
  deleteUser,
};
