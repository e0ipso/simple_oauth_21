/**
 * @file
 * Theme detection and handling utilities.
 */

const { execDrushInTestSite } = require('./drush-helper');

/**
 * Get the active theme from Drupal.
 *
 * @return {string} Active theme machine name
 */
async function getActiveTheme() {
  try {
    const result = await execDrushInTestSite('config:get system.theme default');
    return result.trim() || 'stark';
  } catch (error) {
    console.log(
      `Failed to get active theme: ${error.message}, defaulting to stark`,
    );
    return 'stark';
  }
}

/**
 * Get available regions for the active theme.
 *
 * @param {string} theme - Theme machine name (optional)
 * @return {Array} Array of available region machine names
 */
async function getThemeRegions(theme = null) {
  try {
    const activeTheme = theme || (await getActiveTheme());
    const result = await execDrushInTestSite(
      `theme:list --status=enabled --format=json`,
    );
    const themeData = JSON.parse(result);

    if (themeData[activeTheme] && themeData[activeTheme].regions) {
      return Object.keys(themeData[activeTheme].regions);
    }

    // Fallback to common regions if we can't get theme info
    return ['content', 'header', 'footer', 'sidebar_first', 'sidebar_second'];
  } catch (error) {
    console.log(
      `Failed to get theme regions: ${error.message}, using fallback regions`,
    );
    return ['content', 'header', 'footer'];
  }
}

/**
 * Check if a region exists in the current theme.
 *
 * @param {string} regionName - Region machine name to check
 * @param {string} theme - Theme machine name (optional)
 * @return {boolean} Whether the region exists
 */
async function regionExists(regionName, theme = null) {
  const regions = await getThemeRegions(theme);
  return regions.includes(regionName);
}

module.exports = {
  getActiveTheme,
  getThemeRegions,
  regionExists,
};
