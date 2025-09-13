/**
 * @file
 * Shared constants and configuration for E2E tests.
 */

/**
 * Test timeouts in milliseconds.
 */
const TIMEOUTS = {
  SHORT: 5000,
  MEDIUM: 10000,
  LONG: 30000,
  AJAX: 15000,
  PAGE_LOAD: 20000,
};

/**
 * Default test data.
 */
const TEST_DATA = {
  admin: {
    username: 'admin',
    password: 'admin',
  },
  content: {
    page: {
      title: 'Test Page for Proxy Block',
      body: 'This is a test page created for Proxy Block E2E testing.',
    },
    article: {
      title: 'Test Article for Proxy Block',
      body: 'This is a test article created for Proxy Block E2E testing.',
    },
  },
  blocks: {
    proxyBlock: {
      title: 'Test Proxy Block',
      region: 'content',
    },
    targetBlocks: [
      'system_powered_by_block',
      'system_branding_block',
      'local_tasks_block',
      'page_title_block',
    ],
  },
};

/**
 * Drupal-specific selectors.
 */
const SELECTORS = {
  drupal: {
    adminToolbar: '#toolbar-administration',
    mainContent: '#main-content, .main-content, [role="main"]',
    messages: '.messages',
    successMessage: '.messages--status',
    errorMessage: '.messages--error',
    warningMessage: '.messages--warning',
    ajaxThrobber: '.ajax-progress-throbber, .ajax-progress-bar',
  },
  forms: {
    submit: '#edit-submit, .form-submit',
    cancel: '.form-cancel',
    required: '.required, [required]',
  },
  blocks: {
    proxyBlock: '[data-block-plugin-id*="proxy_block"]',
    blockContent: '.block-content',
    blockTitle: 'h2, .block-title',
  },
};

/**
 * Common viewport sizes for responsive testing.
 */
const VIEWPORTS = {
  mobile: { width: 375, height: 667 },
  tablet: { width: 768, height: 1024 },
  desktop: { width: 1200, height: 800 },
  wide: { width: 1920, height: 1080 },
};

/**
 * Test environment configuration.
 */
const ENVIRONMENT = {
  baseUrl: process.env.DRUPAL_BASE_URL || 'http://localhost',
  isCI: !!process.env.CI,
  theme: process.env.DRUPAL_THEME || 'olivero',
};

/**
 * Proxy Block specific test data.
 */
const PROXY_BLOCK_DATA = {
  configurations: [
    {
      name: 'Simple Proxy Block',
      targetBlock: 'system_powered_by_block',
      expectedContent: 'Powered by',
    },
    {
      name: 'Branding Proxy Block',
      targetBlock: 'system_branding_block',
      expectedContent: 'Drupal',
    },
    {
      name: 'Page Title Proxy Block',
      targetBlock: 'page_title_block',
      expectedContent: '',
    },
  ],
  contextAwareBlocks: [
    {
      name: 'Node Context Block',
      targetBlock: 'contextual_test_block',
      requiredContexts: ['node'],
    },
    {
      name: 'User Context Block',
      targetBlock: 'user_context_block',
      requiredContexts: ['user'],
    },
  ],
};

/**
 * Error patterns to watch for.
 */
const ERROR_PATTERNS = {
  php: ['Fatal error', 'Parse error', 'Notice:', 'Warning:', 'Deprecated:'],
  javascript: ['Uncaught', 'TypeError', 'ReferenceError', 'SyntaxError'],
  drupal: [
    'The website encountered an unexpected error',
    'Drupal\\Core\\Database\\DatabaseExceptionWrapper',
    'InvalidArgumentException',
  ],
};

/**
 * Test utilities and helpers.
 */
const UTILS = {
  generateUniqueId: () =>
    `test_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
  generateTestTitle: (prefix = 'Test') =>
    `${prefix} ${new Date().toISOString()}`,
  sleep: ms =>
    new Promise(resolve => {
      setTimeout(resolve, ms);
    }),
};

module.exports = {
  TIMEOUTS,
  TEST_DATA,
  SELECTORS,
  VIEWPORTS,
  ENVIRONMENT,
  PROXY_BLOCK_DATA,
  ERROR_PATTERNS,
  UTILS,
};
