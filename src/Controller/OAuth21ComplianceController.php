<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\simple_oauth_21\Service\OAuth21ComplianceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OAuth 2.1 compliance dashboard controller.
 *
 * Provides a comprehensive read-only dashboard that displays OAuth 2.1
 * compliance status across all Simple OAuth modules with clear visual
 * indicators and actionable guidance.
 */
final class OAuth21ComplianceController extends ControllerBase {

  /**
   * The OAuth 2.1 compliance service.
   */
  private OAuth21ComplianceService $complianceService;

  /**
   * Constructs an OAuth21ComplianceController object.
   *
   * @param \Drupal\simple_oauth_21\Service\OAuth21ComplianceService $compliance_service
   *   The OAuth 2.1 compliance service.
   */
  public function __construct(OAuth21ComplianceService $compliance_service) {
    $this->complianceService = $compliance_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_oauth_21.compliance_service')
    );
  }

  /**
   * Displays the OAuth 2.1 compliance dashboard.
   *
   * @return array
   *   A render array for the compliance dashboard.
   */
  public function display(): array {
    // Attach CSS library for dashboard styling.
    $build = [];
    $build['#attached']['library'][] = 'simple_oauth_21/compliance-dashboard';

    try {
      $compliance_status = $this->complianceService->getComplianceStatus();
    }
    catch (\Exception $e) {
      // Graceful error handling when service fails.
      $build['error'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--error">' .
        '<h2>' . $this->t('Compliance Service Unavailable') . '</h2>' .
        '<p>' . $this->t('Unable to retrieve OAuth 2.1 compliance status. Please check your configuration and try again.') . '</p>' .
        '</div>',
      ];
      return $build;
    }

    $build['dashboard'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['oauth21-dashboard']],
    ];

    // RFC Implementation Matrix section (top priority).
    $build['dashboard']['rfc_matrix'] = $this->buildRfcImplementationMatrix();

    // Overall status summary section.
    $build['dashboard']['summary'] = $this->buildSummarySection($compliance_status);

    // Quick Start compliance guidance section.
    $build['dashboard']['quick_start'] = $this->buildQuickStartSection($compliance_status);

    // Critical compliance errors section.
    $build['dashboard']['critical_errors'] = $this->buildCriticalErrorsSection($compliance_status);

    // Core requirements section.
    $build['dashboard']['core'] = $this->buildRequirementsSection(
      'core_requirements',
      $this->t('OAuth 2.1 Core Requirements'),
      $this->t('Mandatory requirements that must be met for OAuth 2.1 compliance'),
      $compliance_status['core_requirements'] ?? []
    );

    // Server metadata section.
    $build['dashboard']['metadata'] = $this->buildRequirementsSection(
      'server_metadata',
      $this->t('Server Metadata (RFC 8414)'),
      $this->t('Required server metadata endpoint and discoverability features'),
      $compliance_status['server_metadata'] ?? []
    );

    // Best practices section.
    $build['dashboard']['practices'] = $this->buildRequirementsSection(
      'best_practices',
      $this->t('Security Best Practices'),
      $this->t('Recommended security enhancements for production deployments'),
      $compliance_status['best_practices'] ?? []
    );

    // Missing recommendations section.
    $build['dashboard']['missing_recommendations'] = $this->buildMissingRecommendationsSection($compliance_status);

    // Action items section.
    $build['dashboard']['actions'] = $this->buildActionItemsSection($compliance_status);

    return $build;
  }

  /**
   * Builds the compliance status summary section.
   *
   * @param array $compliance_status
   *   The complete compliance status data.
   *
   * @return array
   *   Render array for the summary section.
   */
  private function buildSummarySection(array $compliance_status): array {
    $overall_status = $compliance_status['overall_status'] ?? [];
    $summary = $compliance_status['summary'] ?? [];

    $status_classes = [
      'fully_compliant' => 'status-fully-compliant',
      'mostly_compliant' => 'status-mostly-compliant',
      'partially_compliant' => 'status-partially-compliant',
      'non_compliant' => 'status-non-compliant',
    ];

    $status_icons = [
      'fully_compliant' => '✅',
      'mostly_compliant' => '✅',
      'partially_compliant' => '⚠️',
      'non_compliant' => '❌',
    ];

    $priority_indicators = [
      'fully_compliant' => '<span class="priority-badge priority-success">COMPLIANT</span>',
      'mostly_compliant' => '<span class="priority-badge priority-success">COMPLIANT</span>',
      'partially_compliant' => '<span class="priority-badge priority-warning">ATTENTION NEEDED</span>',
      'non_compliant' => '<span class="priority-badge priority-critical">CRITICAL ISSUES</span>',
    ];

    $status_key = $overall_status['status'] ?? 'non_compliant';
    $status_class = $status_classes[$status_key] ?? 'status-non-compliant';
    $status_icon = $status_icons[$status_key] ?? '❌';
    $priority_indicator = $priority_indicators[$status_key] ?? $priority_indicators['non_compliant'];

    $section = [
      '#type' => 'details',
      '#title' => $this->t('Overall Compliance Status'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['compliance-section', 'summary-section', $status_class]],
    ];

    $section['status'] = [
      '#type' => 'markup',
      '#markup' => '<div class="compliance-summary enhanced">' .
      '<div class="status-indicator">' .
      '<span class="status-icon">' . $status_icon . '</span>' .
      '<div class="status-content">' .
      '<h3>' . $this->getStatusTitle($status_key) . '</h3>' .
      $priority_indicator .
      '</div>' .
      '</div>' .
      '<p class="status-message">' . ($summary['message'] ?? '') . '</p>' .
      '</div>',
    ];

    // Enhanced score breakdown with progress bars.
    if (!empty($overall_status)) {
      $section['scores'] = [
        '#type' => 'markup',
        '#markup' => $this->buildEnhancedScoreBreakdown($overall_status),
      ];
    }

    return $section;
  }

  /**
   * Builds a requirements section.
   *
   * @param string $section_key
   *   The section key for CSS classes.
   * @param mixed $title
   *   The section title.
   * @param mixed $description
   *   The section description.
   * @param array $requirements
   *   Array of requirements data.
   *
   * @return array
   *   Render array for the requirements section.
   */
  private function buildRequirementsSection(string $section_key, $title, $description, array $requirements): array {
    $section = [
      '#type' => 'details',
      '#title' => $title,
      '#description' => $description,
      '#open' => FALSE,
      '#attributes' => ['class' => ['compliance-section', $section_key . '-section']],
    ];

    if (empty($requirements)) {
      $section['no_requirements'] = [
        '#type' => 'markup',
        '#markup' => '<p class="no-requirements">' . $this->t('No requirements found for this category.') . '</p>',
      ];
      return $section;
    }

    $section['requirements'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['requirements-list']],
    ];

    foreach ($requirements as $req_key => $requirement) {
      $section['requirements'][$req_key] = $this->buildRequirementItem($requirement);
    }

    return $section;
  }

  /**
   * Builds a single requirement item.
   *
   * @param array $requirement
   *   The requirement data.
   *
   * @return array
   *   Render array for the requirement item.
   */
  private function buildRequirementItem(array $requirement): array {
    $status = $requirement['status'] ?? 'non_compliant';
    $level = $requirement['level'] ?? 'required';

    $status_icons = [
      'compliant' => '✅',
      'warning' => '⚠️',
      'non_compliant' => '❌',
      'recommended' => 'ℹ️',
    ];

    $level_classes = [
      'mandatory' => 'level-mandatory',
      'required' => 'level-required',
      'recommended' => 'level-recommended',
    ];

    $item = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'compliance-item',
          'status-' . str_replace('_', '-', $status),
          $level_classes[$level] ?? 'level-required',
        ],
      ],
    ];

    $item['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="requirement-content">' .
      '<div class="requirement-header">' .
      '<span class="status-icon">' . ($status_icons[$status] ?? '❓') . '</span>' .
      '<h4 class="requirement-title">' . ($requirement['title'] ?? '') . '</h4>' .
      '<span class="requirement-level">' . $this->formatLevel($level) . '</span>' .
      '</div>' .
      '<p class="requirement-description">' . ($requirement['description'] ?? '') . '</p>' .
      '<p class="requirement-message">' . ($requirement['message'] ?? '') . '</p>' .
      '</div>',
    ];

    return $item;
  }

  /**
   * Builds the critical compliance errors section.
   *
   * @param array $compliance_status
   *   The complete compliance status data.
   *
   * @return array
   *   Render array for the critical errors section.
   */
  private function buildCriticalErrorsSection(array $compliance_status): array {
    $core_requirements = $compliance_status['core_requirements'] ?? [];
    $server_metadata = $compliance_status['server_metadata'] ?? [];

    // Find all non-compliant mandatory and required items.
    $critical_errors = [];

    foreach ($core_requirements as $key => $requirement) {
      if (($requirement['level'] ?? '') === 'mandatory' &&
          ($requirement['status'] ?? '') === 'non_compliant') {
        $critical_errors[$key] = $requirement;
      }
    }

    foreach ($server_metadata as $key => $requirement) {
      if (($requirement['level'] ?? '') === 'required' &&
          ($requirement['status'] ?? '') === 'non_compliant') {
        $critical_errors[$key] = $requirement;
      }
    }

    if (empty($critical_errors)) {
      return [];
    }

    $section = [
      '#type' => 'details',
      '#title' => $this->t('🚨 Critical Compliance Errors (@count)', ['@count' => count($critical_errors)]),
      '#description' => $this->t('These mandatory OAuth 2.1 requirements are not met. Your implementation is not compliant until these are resolved.'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['compliance-section', 'critical-errors-section', 'status-non-compliant']],
    ];

    $section['errors'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['critical-errors-list']],
    ];

    foreach ($critical_errors as $key => $error) {
      $section['errors'][$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['critical-error-item']],
      ];

      $level_label = ($error['level'] === 'mandatory') ? 'MANDATORY' : 'REQUIRED';

      $section['errors'][$key]['content'] = [
        '#type' => 'markup',
        '#markup' => '<div class="critical-error-content">' .
        '<div class="error-header">' .
        '<h4 class="error-title">❌ ' . ($error['title'] ?? '') . '</h4>' .
        '<span class="error-level level-' . strtolower($error['level'] ?? 'required') . '">' . $level_label . '</span>' .
        '</div>' .
        '<p class="error-description">' . ($error['description'] ?? '') . '</p>' .
        '<p class="error-message"><strong>' . $this->t('Fix Required:') . '</strong> ' . ($error['message'] ?? '') . '</p>' .
        '</div>',
      ];
    }

    // Add quick configuration links.
    $section['quick_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['quick-actions']],
    ];

    $section['quick_actions']['links'] = [
      '#type' => 'markup',
      '#markup' => $this->buildCriticalErrorLinks($critical_errors),
    ];

    return $section;
  }

  /**
   * Builds the missing recommendations section.
   *
   * @param array $compliance_status
   *   The complete compliance status data.
   *
   * @return array
   *   Render array for the missing recommendations section.
   */
  private function buildMissingRecommendationsSection(array $compliance_status): array {
    $best_practices = $compliance_status['best_practices'] ?? [];

    // Filter for non-compliant recommended items.
    // Note: When recommended items are not configured, their status is
    // 'recommended' not 'non_compliant'.
    $missing_recommendations = array_filter($best_practices, function ($item) {
      return ($item['level'] ?? '') === 'recommended' &&
             ($item['status'] ?? '') === 'recommended';
    });

    if (empty($missing_recommendations)) {
      return [];
    }

    $section = [
      '#type' => 'details',
      '#title' => $this->t('Missing Recommended Settings (@count)', ['@count' => count($missing_recommendations)]),
      '#description' => $this->t('These OAuth 2.1 recommended settings are not currently configured. While not mandatory, implementing these will enhance your security posture.'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['compliance-section', 'missing-recommendations-section']],
    ];

    $section['recommendations'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['missing-recommendations-list']],
    ];

    foreach ($missing_recommendations as $key => $recommendation) {
      $section['recommendations'][$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['missing-recommendation-item']],
      ];

      $section['recommendations'][$key]['content'] = [
        '#type' => 'markup',
        '#markup' => '<div class="recommendation-content">' .
        '<div class="recommendation-header">' .
        '<h4 class="recommendation-title">⚠️ ' . ($recommendation['title'] ?? '') . '</h4>' .
        '</div>' .
        '<p class="recommendation-description">' . ($recommendation['description'] ?? '') . '</p>' .
        '<p class="recommendation-message"><strong>' . $this->t('Recommended Action:') . '</strong> ' . ($recommendation['message'] ?? '') . '</p>' .
        '<p class="recommendation-link">' . $this->buildIndividualRecommendationLink($recommendation) . '</p>' .
        '</div>',
      ];
    }

    // Add quick configuration links if applicable.
    $section['quick_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['quick-actions']],
    ];

    $section['quick_actions']['links'] = [
      '#type' => 'markup',
      '#markup' => $this->buildRecommendationLinks($missing_recommendations),
    ];

    return $section;
  }

  /**
   * Builds actionable links for critical errors.
   *
   * @param array $critical_errors
   *   Array of critical error items.
   *
   * @return string
   *   HTML markup with action links.
   */
  private function buildCriticalErrorLinks(array $critical_errors): string {
    $links = [];
    $seen_routes = [];

    // Map each specific error to its exact configuration page.
    foreach ($critical_errors as $error) {
      $title = $error['title'] ?? '';
      $route_name = '';
      $link_text = '';

      // Map specific errors to their exact configuration pages.
      if (strpos($title, 'Enhanced PKCE for Native') !== FALSE) {
        $route_name = 'simple_oauth_native_apps.settings';
        $link_text = $this->t('Enable Enhanced PKCE');
      }
      elseif (strpos($title, 'Custom URI Schemes') !== FALSE) {
        $route_name = 'simple_oauth_native_apps.settings';
        $link_text = $this->t('Enable Custom URI Schemes');
      }
      elseif (strpos($title, 'Loopback Redirects') !== FALSE) {
        $route_name = 'simple_oauth_native_apps.settings';
        $link_text = $this->t('Enable Loopback Redirects');
      }
      elseif (strpos($title, 'WebView Detection') !== FALSE) {
        $route_name = 'simple_oauth_native_apps.settings';
        $link_text = $this->t('Enable WebView Detection');
      }
      elseif (strpos($title, 'PKCE') !== FALSE && strpos($title, 'Mandatory') !== FALSE) {
        $route_name = 'simple_oauth_pkce.settings';
        $link_text = $this->t('Fix PKCE Enforcement');
      }
      elseif (strpos($title, 'S256') !== FALSE) {
        $route_name = 'simple_oauth_pkce.settings';
        $link_text = $this->t('Enable S256 Method');
      }
      elseif (strpos($title, 'Implicit Grant') !== FALSE) {
        $route_name = 'oauth2_token.settings';
        $link_text = $this->t('Disable Implicit Grant');
      }
      elseif (strpos($title, 'Server Metadata') !== FALSE) {
        $route_name = 'simple_oauth_server_metadata.settings';
        $link_text = $this->t('Configure Server Metadata');
      }

      // Only add unique routes to avoid duplicates.
      if ($route_name && !isset($seen_routes[$route_name])) {
        try {
          $link = Link::createFromRoute($link_text, $route_name);
          $links[] = $link->toString();
          $seen_routes[$route_name] = TRUE;
        }
        catch (\Exception $e) {
          // Route doesn't exist, skip.
        }
      }
    }

    if (empty($links)) {
      return '';
    }

    $links_html = Markup::create(implode(' | ', $links));
    return '<div class="critical-error-links"><p><strong>🚨 ' . $this->t('Fix These Issues:') . '</strong> ' . $links_html . '</p></div>';
  }

  /**
   * Builds an individual recommendation link for a specific recommendation.
   *
   * @param array $recommendation
   *   The recommendation item.
   *
   * @return string
   *   HTML markup with the specific recommendation link.
   */
  private function buildIndividualRecommendationLink(array $recommendation): string {
    $title = $recommendation['title'] ?? '';
    $route_name = '';
    $link_text = '';

    // Map specific recommendations to their exact configuration pages.
    if (strpos($title, 'Token Revocation') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Token Revocation Endpoint');
    }
    elseif (strpos($title, 'Token Introspection') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Token Introspection Endpoint');
    }
    elseif (strpos($title, 'Client Registration') !== FALSE || strpos($title, 'Registration Endpoint') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Client Registration Endpoint');
    }
    elseif (strpos($title, 'WebView') !== FALSE) {
      $route_name = 'simple_oauth_native_apps.settings';
      $link_text = $this->t('Configure WebView Detection');
    }
    elseif (strpos($title, 'Native Client Security') !== FALSE) {
      $route_name = 'simple_oauth_native_apps.settings';
      $link_text = $this->t('Configure Native Security');
    }
    elseif (strpos($title, 'Exact Redirect') !== FALSE) {
      $route_name = 'simple_oauth_native_apps.settings';
      $link_text = $this->t('Configure Redirect Matching');
    }
    elseif (strpos($title, 'Token Expiration') !== FALSE) {
      $route_name = 'oauth2_token.settings';
      $link_text = $this->t('Configure Token Expiration');
    }
    elseif (strpos($title, 'Service Documentation') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Service Documentation URL');
    }
    elseif (strpos($title, 'Operator Policy') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Operator Policy URI');
    }
    elseif (strpos($title, 'Terms of Service') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Terms of Service URI');
    }
    elseif (strpos($title, 'UI Locales') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Supported UI Locales');
    }
    elseif (strpos($title, 'Additional Claims') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Additional Claims');
    }
    elseif (strpos($title, 'Additional Signing Algorithms') !== FALSE) {
      $route_name = 'simple_oauth_server_metadata.settings';
      $link_text = $this->t('Configure Additional Signing Algorithms');
    }

    if (!empty($route_name)) {
      try {
        $link = Link::createFromRoute($link_text, $route_name);
        return '→ ' . $link->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist, return empty.
        return '';
      }
    }

    return '';
  }

  /**
   * Builds actionable links for missing recommendations.
   *
   * @param array $missing_recommendations
   *   Array of missing recommendation items.
   *
   * @return string
   *   HTML markup with action links.
   */
  private function buildRecommendationLinks(array $missing_recommendations): string {
    $links = [];
    $relevant_modules = [];

    // Analyze which specific modules need configuration based on the
    // actual recommendations.
    foreach ($missing_recommendations as $rec) {
      $title = $rec['title'] ?? '';

      // Map recommendations to specific configuration pages.
      if (strpos($title, 'WebView') !== FALSE ||
          strpos($title, 'Native Client Security') !== FALSE ||
          strpos($title, 'Exact Redirect') !== FALSE) {
        $relevant_modules['native_apps'] = TRUE;
      }
      elseif (strpos($title, 'Token Expiration') !== FALSE) {
        $relevant_modules['oauth'] = TRUE;
      }
      elseif (strpos($title, 'Server Metadata') !== FALSE) {
        $relevant_modules['metadata'] = TRUE;
      }
    }

    // Only include links for modules that have relevant recommendations.
    try {
      if (isset($relevant_modules['native_apps']) &&
          $this->complianceService->isModuleEnabledWithFallback('simple_oauth_native_apps')) {
        $native_link = Link::createFromRoute(
          $this->t('Native Apps Settings'),
          'simple_oauth_native_apps.settings'
        );
        $links[] = $native_link->toString();
      }

      if (isset($relevant_modules['metadata']) &&
          $this->complianceService->isModuleEnabledWithFallback('simple_oauth_server_metadata')) {
        $metadata_link = Link::createFromRoute(
          $this->t('Server Metadata Settings'),
          'simple_oauth_server_metadata.settings'
        );
        $links[] = $metadata_link->toString();
      }

      if (isset($relevant_modules['oauth'])) {
        $oauth_link = Link::createFromRoute(
          $this->t('OAuth Settings'),
          'oauth2_token.settings'
        );
        $links[] = $oauth_link->toString();
      }
    }
    catch (\Exception $e) {
      // Routes don't exist, skip.
    }

    if (empty($links)) {
      return '';
    }

    $links_html = Markup::create(implode(' | ', $links));
    return '<div class="recommendation-links"><p><strong>' . $this->t('Quick Configuration:') . '</strong> ' . $links_html . '</p></div>';
  }

  /**
   * Builds the action items section.
   *
   * @param array $compliance_status
   *   The complete compliance status data.
   *
   * @return array
   *   Render array for the action items section.
   */
  private function buildActionItemsSection(array $compliance_status): array {
    $summary = $compliance_status['summary'] ?? [];
    $has_issues = $summary['has_critical_issues'] ?? FALSE;

    // Only show Action Items section if there are critical issues, since
    // recommendations
    // are now handled by the dedicated "Missing Recommended Settings" section.
    if (!$has_issues) {
      return [];
    }

    $section = [
      '#type' => 'details',
      '#title' => $this->t('Action Items'),
      '#description' => $this->t('Critical configuration changes required for OAuth 2.1 compliance'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['compliance-section', 'action-items-section']],
    ];

    $section['critical'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['critical-issues']],
    ];

    $section['critical']['title'] = [
      '#type' => 'markup',
      '#markup' => '<h4 class="issues-title critical">🚨 ' . $this->t('Critical Issues') . '</h4>',
    ];

    $section['critical']['list'] = [
      '#theme' => 'item_list',
      '#items' => $summary['critical_issues'] ?? [],
      '#attributes' => ['class' => ['critical-issues-list']],
    ];

    $section['critical']['links'] = [
      '#type' => 'markup',
      '#markup' => $this->buildActionLinks(TRUE),
    ];

    return $section;
  }

  /**
   * Builds actionable links for configuration pages.
   *
   * @param bool $critical
   *   Whether these are critical issues or recommendations.
   *
   * @return string
   *   HTML markup with action links.
   */
  private function buildActionLinks(bool $critical): string {
    $links = [];

    // Simple OAuth main settings.
    try {
      $oauth_link = Link::createFromRoute(
        $this->t('OAuth Settings'),
        'oauth2_token.settings'
      );
      $links[] = $oauth_link->toString();
    }
    catch (\Exception $e) {
      // Route doesn't exist, skip.
    }

    // PKCE settings - enhanced submodule detection.
    if ($this->complianceService->isModuleEnabledWithFallback('simple_oauth_pkce')) {
      try {
        $pkce_link = Link::createFromRoute(
          $this->t('PKCE Settings'),
          'simple_oauth_pkce.settings'
        );
        $links[] = $pkce_link->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist, skip.
      }
    }

    // Server metadata settings - enhanced submodule detection.
    if ($this->complianceService->isModuleEnabledWithFallback('simple_oauth_server_metadata')) {
      try {
        $metadata_link = Link::createFromRoute(
          $this->t('Server Metadata Settings'),
          'simple_oauth_server_metadata.settings'
        );
        $links[] = $metadata_link->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist, skip.
      }
    }

    // Native apps settings (for recommendations) - enhanced submodule
    // detection.
    if (!$critical && $this->complianceService->isModuleEnabledWithFallback('simple_oauth_native_apps')) {
      try {
        $native_link = Link::createFromRoute(
          $this->t('Native Apps Settings'),
          'simple_oauth_native_apps.settings'
        );
        $links[] = $native_link->toString();
      }
      catch (\Exception $e) {
        // Route doesn't exist, skip.
      }
    }

    if (empty($links)) {
      return '';
    }

    // Create the links HTML directly using Markup::create to prevent escaping.
    $links_html = Markup::create(implode(' | ', $links));

    // Build the complete HTML string.
    return '<div class="action-links"><p>Quick links: ' . $links_html . '</p></div>';
  }

  /**
   * Gets the human-readable status title.
   *
   * @param string $status
   *   The status key.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The formatted status title.
   */
  private function getStatusTitle(string $status) {
    return match($status) {
      'fully_compliant' => $this->t('Fully OAuth 2.1 Compliant'),
      'mostly_compliant' => $this->t('OAuth 2.1 Compliant'),
      'partially_compliant' => $this->t('Partially Compliant'),
      'non_compliant' => $this->t('Non-Compliant'),
      default => $this->t('Status Unknown'),
    };
  }

  /**
   * Builds the Quick Start compliance guidance section.
   *
   * Provides step-by-step prioritized guidance to help administrators quickly
   * understand and achieve OAuth 2.1 compliance with one-click enablement
   * suggestions where possible.
   *
   * @param array $compliance_status
   *   The complete compliance status data.
   *
   * @return array
   *   Render array for the Quick Start section.
   */
  private function buildQuickStartSection(array $compliance_status): array {
    $overall_status = $compliance_status['overall_status'] ?? [];
    $current_status = $overall_status['status'] ?? 'non_compliant';

    // Don't show Quick Start section if already fully compliant.
    if ($current_status === 'fully_compliant') {
      return [];
    }

    $section = [
      '#type' => 'details',
      '#title' => $this->t('🚀 Quick Start to OAuth 2.1 Compliance'),
      '#description' => $this->t('Follow these prioritized steps to achieve OAuth 2.1 compliance. Steps are ordered by impact and ease of implementation.'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['compliance-section', 'quick-start-section']],
    ];

    // Get prioritized compliance steps.
    $steps = $this->getComplianceSteps($compliance_status);

    if (empty($steps)) {
      $section['no_steps'] = [
        '#type' => 'markup',
        '#markup' => '<p class="no-steps">' . $this->t('All compliance steps are complete. Your OAuth implementation is on track!') . '</p>',
      ];
      return $section;
    }

    // Progress indicator.
    $total_steps = count($this->getAllPossibleSteps());
    $completed_steps = $total_steps - count($steps);
    $progress_percentage = $total_steps > 0 ? floor(($completed_steps / $total_steps) * 100) : 0;

    $section['progress'] = [
      '#type' => 'markup',
      '#markup' => '<div class="quick-start-progress">' .
      '<div class="progress-bar">' .
      '<div class="progress-fill" style="width: ' . $progress_percentage . '%"></div>' .
      '</div>' .
      '<div class="progress-text">' .
      $this->t('@completed of @total steps completed (@percentage%)', [
        '@completed' => $completed_steps,
        '@total' => $total_steps,
        '@percentage' => $progress_percentage,
      ]) .
      '</div>' .
      '</div>',
    ];

    // Estimated time to completion.
    $estimated_time = $this->estimateCompletionTime($steps);
    if ($estimated_time > 0) {
      $section['time_estimate'] = [
        '#type' => 'markup',
        '#markup' => '<div class="time-estimate">' .
        '<strong>' . $this->t('Estimated time to full compliance: @time minutes', ['@time' => $estimated_time]) . '</strong>' .
        '</div>',
      ];
    }

    // Render steps.
    $section['steps'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['quick-start-steps']],
    ];

    foreach ($steps as $step_key => $step) {
      $section['steps'][$step_key] = $this->buildQuickStartStep($step, $step_key);
    }

    return $section;
  }

  /**
   * Gets prioritized compliance steps based on current status.
   *
   * @param array $compliance_status
   *   The complete compliance status data.
   *
   * @return array
   *   Array of prioritized steps that need completion.
   */
  private function getComplianceSteps(array $compliance_status): array {
    $all_steps = $this->getAllPossibleSteps();
    $core_requirements = $compliance_status['core_requirements'] ?? [];
    $server_metadata = $compliance_status['server_metadata'] ?? [];
    $best_practices = $compliance_status['best_practices'] ?? [];

    $incomplete_steps = [];

    foreach ($all_steps as $step_key => $step_definition) {
      $is_complete = $this->isStepComplete($step_key, $step_definition, $core_requirements, $server_metadata, $best_practices);

      if (!$is_complete) {
        $incomplete_steps[$step_key] = $step_definition;
      }
    }

    // Sort by priority (lower number = higher priority).
    uasort($incomplete_steps, function ($a, $b) {
      return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
    });

    return $incomplete_steps;
  }

  /**
   * Gets all possible compliance steps with their definitions.
   *
   * @return array
   *   Array of all possible compliance steps.
   */
  private function getAllPossibleSteps(): array {
    return [
      'enable_pkce' => [
        'title' => $this->t('Enable PKCE Module'),
        'description' => $this->t('PKCE (Proof Key for Code Exchange) is mandatory for OAuth 2.1 compliance'),
        'impact' => 'critical',
        'difficulty' => 'easy',
        'priority' => 1,
        'time_estimate' => 2,
        'module' => 'simple_oauth_pkce',
        'route' => NULL,
        'one_click' => FALSE,
        'category' => 'core_requirements',
      ],
      'configure_pkce_mandatory' => [
        'title' => $this->t('Set PKCE to Mandatory'),
        'description' => $this->t('Configure PKCE enforcement to "Mandatory" for all authorization code flows'),
        'impact' => 'critical',
        'difficulty' => 'easy',
        'priority' => 2,
        'time_estimate' => 1,
        'module' => 'simple_oauth_pkce',
        'route' => 'simple_oauth_pkce.settings',
        'one_click' => TRUE,
        'category' => 'core_requirements',
      ],
      'enable_s256' => [
        'title' => $this->t('Enable S256 Challenge Method'),
        'description' => $this->t('Enable SHA256 challenge method for PKCE security'),
        'impact' => 'critical',
        'difficulty' => 'easy',
        'priority' => 3,
        'time_estimate' => 1,
        'module' => 'simple_oauth_pkce',
        'route' => 'simple_oauth_pkce.settings',
        'one_click' => TRUE,
        'category' => 'core_requirements',
      ],
      'disable_implicit' => [
        'title' => $this->t('Disable Implicit Grant'),
        'description' => $this->t('OAuth 2.1 deprecates the implicit grant flow for security'),
        'impact' => 'critical',
        'difficulty' => 'easy',
        'priority' => 4,
        'time_estimate' => 1,
        'module' => 'simple_oauth',
        'route' => 'oauth2_token.settings',
        'one_click' => TRUE,
        'category' => 'core_requirements',
      ],
      'enable_server_metadata' => [
        'title' => $this->t('Enable Server Metadata Module'),
        'description' => $this->t('Server metadata endpoints provide OAuth 2.1 discoverability'),
        'impact' => 'high',
        'difficulty' => 'easy',
        'priority' => 5,
        'time_estimate' => 2,
        'module' => 'simple_oauth_server_metadata',
        'route' => NULL,
        'one_click' => FALSE,
        'category' => 'server_metadata',
      ],
      'enable_native_apps' => [
        'title' => $this->t('Enable Native Apps Module'),
        'description' => $this->t('Enhanced security for mobile and native OAuth clients'),
        'impact' => 'medium',
        'difficulty' => 'easy',
        'priority' => 6,
        'time_estimate' => 2,
        'module' => 'simple_oauth_native_apps',
        'route' => NULL,
        'one_click' => FALSE,
        'category' => 'best_practices',
      ],
      'configure_native_security' => [
        'title' => $this->t('Configure Native App Security'),
        'description' => $this->t('Enable custom URI schemes, loopback redirects, and enhanced PKCE for native apps'),
        'impact' => 'medium',
        'difficulty' => 'medium',
        'priority' => 7,
        'time_estimate' => 5,
        'module' => 'simple_oauth_native_apps',
        'route' => 'simple_oauth_native_apps.settings',
        'one_click' => FALSE,
        'category' => 'best_practices',
      ],
    ];
  }

  /**
   * Checks if a specific step is complete.
   *
   * @param string $step_key
   *   The step key.
   * @param array $step_definition
   *   The step definition.
   * @param array $core_requirements
   *   Core requirements status.
   * @param array $server_metadata
   *   Server metadata status.
   * @param array $best_practices
   *   Best practices status.
   *
   * @return bool
   *   TRUE if the step is complete.
   */
  private function isStepComplete(string $step_key, array $step_definition, array $core_requirements, array $server_metadata, array $best_practices): bool {
    $category = $step_definition['category'] ?? 'core_requirements';
    $status_data = match($category) {
      'core_requirements' => $core_requirements,
      'server_metadata' => $server_metadata,
      'best_practices' => $best_practices,
      default => $core_requirements,
    };

    return match($step_key) {
      'enable_pkce' => $this->complianceService->isModuleEnabledWithFallback('simple_oauth_pkce'),
      'configure_pkce_mandatory' => isset($status_data['pkce_enforcement']) && $status_data['pkce_enforcement']['status'] === 'compliant',
      'enable_s256' => isset($status_data['pkce_s256']) && $status_data['pkce_s256']['status'] === 'compliant',
      'disable_implicit' => isset($status_data['implicit_grant_disabled']) && $status_data['implicit_grant_disabled']['status'] === 'compliant',
      'enable_server_metadata' => $this->complianceService->isModuleEnabledWithFallback('simple_oauth_server_metadata'),
      'enable_native_apps' => $this->complianceService->isModuleEnabledWithFallback('simple_oauth_native_apps'),
      'configure_native_security' => $this->isNativeAppSecurityConfigured($status_data),
      default => FALSE,
    };
  }

  /**
   * Checks if native app security is properly configured.
   *
   * @param array $status_data
   *   The status data to check.
   *
   * @return bool
   *   TRUE if native app security is configured.
   */
  private function isNativeAppSecurityConfigured(array $status_data): bool {
    $custom_uri = isset($status_data['custom_uri_schemes']) && $status_data['custom_uri_schemes']['status'] === 'compliant';
    $loopback = isset($status_data['loopback_redirects']) && $status_data['loopback_redirects']['status'] === 'compliant';
    $enhanced_pkce = isset($status_data['enhanced_pkce_native']) && $status_data['enhanced_pkce_native']['status'] === 'compliant';

    return $custom_uri && $loopback && $enhanced_pkce;
  }

  /**
   * Builds a single Quick Start step.
   *
   * @param array $step
   *   The step definition.
   * @param string $step_key
   *   The step key.
   *
   * @return array
   *   Render array for the step.
   */
  private function buildQuickStartStep(array $step, string $step_key): array {
    $impact_classes = [
      'critical' => 'impact-critical',
      'high' => 'impact-high',
      'medium' => 'impact-medium',
      'low' => 'impact-low',
    ];

    $impact_icons = [
      'critical' => '🚨',
      'high' => '⚠️',
      'medium' => 'ℹ️',
      'low' => '💡',
    ];

    $difficulty_labels = [
      'easy' => $this->t('Easy'),
      'medium' => $this->t('Medium'),
      'hard' => $this->t('Advanced'),
    ];

    $impact = $step['impact'] ?? 'medium';
    $difficulty = $step['difficulty'] ?? 'medium';
    $priority = $step['priority'] ?? 999;

    $step_container = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'quick-start-step',
          $impact_classes[$impact] ?? 'impact-medium',
          'difficulty-' . $difficulty,
        ],
      ],
    ];

    $step_container['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="step-content">' .
      '<div class="step-header">' .
      '<span class="step-number">' . $priority . '</span>' .
      '<span class="step-icon">' . ($impact_icons[$impact] ?? 'ℹ️') . '</span>' .
      '<h4 class="step-title">' . $step['title'] . '</h4>' .
      '<div class="step-meta">' .
      '<span class="step-difficulty">' . ($difficulty_labels[$difficulty] ?? $this->t('Medium')) . '</span>' .
      ($step['time_estimate'] ? '<span class="step-time">' . $this->t('@time min', ['@time' => $step['time_estimate']]) . '</span>' : '') .
      '</div>' .
      '</div>' .
      '<p class="step-description">' . $step['description'] . '</p>' .
      '</div>',
    ];

    // Add action links.
    if (!empty($step['route'])) {
      try {
        $route_link = Link::createFromRoute(
          $this->t('Configure'),
          $step['route']
        );

        $one_click_text = $step['one_click'] ? ' (' . $this->t('Quick Fix Available') . ')' : '';

        $step_container['actions'] = [
          '#type' => 'markup',
          '#markup' => '<div class="step-actions">' .
          '<strong>' . $this->t('Action:') . '</strong> ' .
          $route_link->toString() . $one_click_text .
          '</div>',
        ];
      }
      catch (\Exception $e) {
        // Route doesn't exist, provide fallback.
        $step_container['actions'] = [
          '#type' => 'markup',
          '#markup' => '<div class="step-actions">' .
          '<strong>' . $this->t('Action:') . '</strong> ' .
          $this->t('Enable the @module module to access configuration', ['@module' => $step['module']]) .
          '</div>',
        ];
      }
    }
    elseif (!empty($step['module'])) {
      // Module needs to be enabled.
      $step_container['actions'] = [
        '#type' => 'markup',
        '#markup' => '<div class="step-actions">' .
        '<strong>' . $this->t('Action:') . '</strong> ' .
        $this->t('Enable the @module module', ['@module' => $step['module']]) .
        '</div>',
      ];
    }

    return $step_container;
  }

  /**
   * Estimates total time to complete remaining steps.
   *
   * @param array $steps
   *   Array of remaining steps.
   *
   * @return int
   *   Estimated time in minutes.
   */
  private function estimateCompletionTime(array $steps): int {
    $total_time = 0;

    foreach ($steps as $step) {
      $total_time += $step['time_estimate'] ?? 3;
    }

    return $total_time;
  }

  /**
   * Formats requirement level for display.
   *
   * @param string $level
   *   The requirement level.
   *
   * @return string
   *   The formatted level.
   */
  private function formatLevel(string $level): string {
    return match($level) {
      'mandatory' => 'MANDATORY',
      'required' => 'REQUIRED',
      'recommended' => 'RECOMMENDED',
      default => strtoupper($level),
    };
  }

  /**
   * Gets RFC definitions with implementation details.
   *
   * @return array
   *   Array of RFC definitions with metadata and implementation details.
   */
  private function getRfcDefinitions(): array {
    return [
      'rfc_7591' => [
        'number' => '7591',
        'title' => 'Dynamic Client Registration',
        'description' => 'Full CRUD operations, metadata support',
        'module' => 'simple_oauth_client_registration',
        'endpoint' => '/oauth/register',
        'url' => 'https://datatracker.ietf.org/doc/html/rfc7591',
      ],
      'rfc_7636' => [
        'number' => '7636',
        'title' => 'PKCE (Proof Key for Code Exchange)',
        'description' => 'S256 challenge method, mandatory enforcement',
        'module' => 'simple_oauth_pkce',
        'endpoint' => '/oauth/authorize',
        'url' => 'https://datatracker.ietf.org/doc/html/rfc7636',
      ],
      'rfc_8252' => [
        'number' => '8252',
        'title' => 'OAuth for Native Apps',
        'description' => 'Custom URI schemes, loopback redirects, WebView detection',
        'module' => 'simple_oauth_native_apps',
        'endpoint' => '/oauth/authorize',
        'url' => 'https://datatracker.ietf.org/doc/html/rfc8252',
      ],
      'rfc_8414' => [
        'number' => '8414',
        'title' => 'OAuth Server Metadata',
        'description' => 'Well-known discovery endpoint',
        'module' => 'simple_oauth_server_metadata',
        'endpoint' => '/.well-known/oauth-authorization-server',
        'url' => 'https://datatracker.ietf.org/doc/html/rfc8414',
      ],
      'rfc_9728' => [
        'number' => '9728',
        'title' => 'OAuth Resource Server Metadata',
        'description' => 'Resource server discovery endpoint',
        'module' => 'simple_oauth_server_metadata',
        'endpoint' => '/.well-known/oauth-protected-resource',
        'url' => 'https://datatracker.ietf.org/doc/html/rfc9728',
      ],
      'rfc_8628' => [
        'number' => '8628',
        'title' => 'Device Authorization Grant',
        'description' => 'Device flow for input-constrained devices',
        'module' => 'simple_oauth_device_flow',
        'endpoint' => '/oauth/device_authorization',
        'url' => 'https://datatracker.ietf.org/doc/html/rfc8628',
      ],
    ];
  }

  /**
   * Builds the RFC Implementation Matrix section.
   *
   * @return array
   *   Render array for the RFC Implementation Matrix section.
   */
  private function buildRfcImplementationMatrix(): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('🔧 RFC Implementation Matrix'),
      '#description' => $this->t('OAuth 2.1 ecosystem implementation status showing all supported RFCs with their current configuration state.'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['compliance-section', 'rfc-matrix-section']],
    ];

    $rfcs = $this->getRfcDefinitions();
    $rfc_data = [];

    foreach ($rfcs as $rfc) {
      $module_status = $this->complianceService->getModuleStatus($rfc['module']);
      $status_info = $this->getRfcStatusInfo($rfc, $module_status);

      $rfc_data[] = [
        'rfc' => $rfc,
        'status' => $status_info,
        'module_status' => $module_status,
      ];
    }

    $section['matrix'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rfc-implementation-matrix']],
    ];

    $section['matrix']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('RFC'),
        $this->t('Title & Description'),
        $this->t('Module Status'),
        $this->t('Actions'),
      ],
      '#attributes' => ['class' => ['rfc-matrix-table']],
      '#rows' => [],
    ];

    foreach ($rfc_data as $index => $data) {
      $rfc = $data['rfc'];
      $status = $data['status'];
      $module_status = $data['module_status'];

      $section['matrix']['table']['#rows'][$index] = [
        'data' => [
          // RFC column.
          [
            'data' => Markup::create(
              '<strong><a href="' . $rfc['url'] . '" target="_blank">RFC ' . $rfc['number'] . '</a></strong>'
            ),
            'class' => ['rfc-number'],
          ],
          // Title & Description column.
          [
            'data' => Markup::create(
              '<div class="rfc-title">' . $rfc['title'] . '</div>' .
              '<div class="rfc-description">' . $rfc['description'] . '</div>' .
              '<div class="rfc-endpoint"><code>' . $rfc['endpoint'] . '</code></div>'
            ),
            'class' => ['rfc-details'],
          ],
          // Module Status column.
          [
            'data' => Markup::create($this->formatModuleStatus($module_status)),
            'class' => ['module-status', 'status-' . $status['status_class']],
          ],
          // Actions column.
          [
            'data' => Markup::create($this->buildRfcActionLinks($rfc, $module_status)),
            'class' => ['rfc-actions'],
          ],
        ],
        'class' => ['rfc-row', 'rfc-' . $rfc['number'], 'status-' . $status['status_class']],
      ];
    }

    return $section;
  }

  /**
   * Gets RFC status information based on module status.
   *
   * @param array $rfc
   *   The RFC definition.
   * @param array $module_status
   *   The module status information.
   *
   * @return array
   *   Array with status information including icon, text, and CSS class.
   */
  private function getRfcStatusInfo(array $rfc, array $module_status): array {
    if ($module_status['enabled']) {
      // Check if properly configured.
      $is_configured = $this->isRfcConfigured($rfc, $module_status);

      if ($is_configured) {
        return [
          'icon' => '✅',
          'text' => 'Enabled & Configured',
          'status_class' => 'enabled',
        ];
      }
      else {
        return [
          'icon' => '⚠️',
          'text' => 'Enabled (Needs Configuration)',
          'status_class' => 'warning',
        ];
      }
    }
    elseif ($module_status['available']) {
      return [
        'icon' => '🔧',
        'text' => 'Available (Not Enabled)',
        'status_class' => 'available',
      ];
    }
    else {
      return [
        'icon' => '❌',
        'text' => 'Not Installed',
        'status_class' => 'not-installed',
      ];
    }
  }

  /**
   * Checks if an RFC is properly configured.
   *
   * @param array $rfc
   *   The RFC definition.
   * @param array $module_status
   *   The module status information.
   *
   * @return bool
   *   TRUE if the RFC is properly configured.
   */
  private function isRfcConfigured(array $rfc, array $module_status): bool {
    if (!$module_status['enabled']) {
      return FALSE;
    }

    $module_name = $rfc['module'];
    $config = $this->complianceService->getModuleConfigWithFallback($module_name);

    if (!$config) {
      return FALSE;
    }

    // RFC-specific configuration checks.
    switch ($module_name) {
      case 'simple_oauth_pkce':
        $enforcement = $config->get('enforcement') ?? 'optional';
        $s256_enabled = (bool) ($config->get('s256_enabled') ?? FALSE);
        return $enforcement === 'mandatory' && $s256_enabled;

      case 'simple_oauth_server_metadata':
        // Check if metadata endpoint is accessible.
        try {
          Url::fromRoute('simple_oauth_server_metadata.well_known');
          return TRUE;
        }
        catch (\Exception $e) {
          return FALSE;
        }

      case 'simple_oauth_native_apps':
        $allow_custom_schemes = (bool) ($config->get('allow.custom_uri_schemes') ??
                                        $config->get('allow_custom_uri_schemes') ?? FALSE);
        $allow_loopback = (bool) ($config->get('allow.loopback_redirects') ??
                                  $config->get('allow_loopback_redirects') ?? FALSE);
        return $allow_custom_schemes && $allow_loopback;

      case 'simple_oauth_client_registration':
        // Basic check - if module is enabled, assume it's configured.
        return TRUE;

      case 'simple_oauth_device_flow':
        // Basic check - if module is enabled, assume it's configured.
        return TRUE;

      default:
        return TRUE;
    }
  }

  /**
   * Formats module status for display.
   *
   * @param array $module_status
   *   The module status information.
   *
   * @return string
   *   Formatted module status HTML.
   */
  private function formatModuleStatus(array $module_status): string {
    if ($module_status['enabled']) {
      return '<div class="module-enabled">' .
        '<div class="module-name">' . ($module_status['enabled_name'] ?? 'Unknown') . '</div>' .
        '</div>';
    }
    elseif ($module_status['available']) {
      return '<div class="module-available">Available for Enable</div>';
    }
    else {
      return '<div class="module-not-installed">Not Installed</div>';
    }
  }

  /**
   * Builds action links for an RFC.
   *
   * @param array $rfc
   *   The RFC definition.
   * @param array $module_status
   *   The module status information.
   *
   * @return string
   *   HTML markup with action links.
   */
  private function buildRfcActionLinks(array $rfc, array $module_status): string {
    $links = [];

    if ($module_status['enabled']) {
      // Link to configuration page.
      $config_route = $this->getRfcConfigRoute($rfc['module']);
      if ($config_route) {
        try {
          $config_link = Link::createFromRoute(
            $this->t('Configure'),
            $config_route
          );
          $links[] = $config_link->toString();
        }
        catch (\Exception $e) {
          // Route doesn't exist.
        }
      }

      // Link to endpoint (if applicable).
      if ($rfc['endpoint'] && $rfc['endpoint'] !== '/oauth/authorize') {
        try {
          $endpoint_url = Url::fromUserInput($rfc['endpoint'], ['absolute' => TRUE]);
          $endpoint_link = Link::fromTextAndUrl(
            $this->t('View Endpoint'),
            $endpoint_url
          );
          $links[] = $endpoint_link->toString();
        }
        catch (\Exception $e) {
          // Invalid endpoint URL.
        }
      }
    }
    elseif ($module_status['available']) {
      $links[] = '<span class="action-enable">' . $this->t('Enable Module') . '</span>';
    }
    else {
      $links[] = '<span class="action-install">' . $this->t('Install Required') . '</span>';
    }

    // Always include documentation link.
    $doc_link = Link::fromTextAndUrl(
      $this->t('RFC Docs'),
      Url::fromUri($rfc['url'], ['attributes' => ['target' => '_blank']])
    );
    $links[] = $doc_link->toString();

    return '<div class="rfc-action-links">' . implode(' | ', $links) . '</div>';
  }

  /**
   * Gets the configuration route for a module.
   *
   * @param string $module_name
   *   The module name.
   *
   * @return string|null
   *   The route name or NULL if not found.
   */
  private function getRfcConfigRoute(string $module_name): ?string {
    $route_mapping = [
      'simple_oauth_pkce' => 'simple_oauth_pkce.settings',
      'simple_oauth_server_metadata' => 'simple_oauth_server_metadata.settings',
      'simple_oauth_native_apps' => 'simple_oauth_native_apps.settings',
      'simple_oauth_client_registration' => 'simple_oauth_client_registration.settings',
      'simple_oauth_device_flow' => 'simple_oauth_device_flow.settings',
    ];

    return $route_mapping[$module_name] ?? NULL;
  }

  /**
   * Builds enhanced score breakdown with progress bars.
   *
   * @param array $overall_status
   *   The overall status data with scores.
   *
   * @return string
   *   HTML markup for enhanced score breakdown.
   */
  private function buildEnhancedScoreBreakdown(array $overall_status): string {
    $mandatory = $overall_status['mandatory_score'] ?? [];
    $required = $overall_status['required_score'] ?? [];
    $recommended = $overall_status['recommended_score'] ?? [];

    $html = '<div class="score-breakdown enhanced">';

    // Core Requirements with progress bar.
    $mandatory_percentage = $mandatory['percentage'] ?? 0;
    $html .= '<div class="score-item mandatory">';
    $html .= '<div class="score-header">';
    $html .= '<span class="score-label">' . $this->t('Core Requirements') . '</span>';
    $html .= '<span class="score-value">' . ($mandatory['compliant'] ?? 0) . '/' . ($mandatory['total'] ?? 0) . '</span>';
    $html .= '<span class="score-percentage">(' . $mandatory_percentage . '%)</span>';
    $html .= '</div>';
    $html .= '<div class="progress-bar mandatory"><div class="progress-fill" style="width: ' . $mandatory_percentage . '%"></div></div>';
    $html .= '</div>';

    // Server Metadata with progress bar.
    $required_percentage = $required['percentage'] ?? 0;
    $html .= '<div class="score-item required">';
    $html .= '<div class="score-header">';
    $html .= '<span class="score-label">' . $this->t('Server Metadata') . '</span>';
    $html .= '<span class="score-value">' . ($required['compliant'] ?? 0) . '/' . ($required['total'] ?? 0) . '</span>';
    $html .= '<span class="score-percentage">(' . $required_percentage . '%)</span>';
    $html .= '</div>';
    $html .= '<div class="progress-bar required"><div class="progress-fill" style="width: ' . $required_percentage . '%"></div></div>';
    $html .= '</div>';

    // Best Practices with progress bar.
    $recommended_percentage = $recommended['percentage'] ?? 0;
    $html .= '<div class="score-item recommended">';
    $html .= '<div class="score-header">';
    $html .= '<span class="score-label">' . $this->t('Best Practices') . '</span>';
    $html .= '<span class="score-value">' . ($recommended['compliant'] ?? 0) . '/' . ($recommended['total'] ?? 0) . '</span>';
    $html .= '<span class="score-percentage">(' . $recommended_percentage . '%)</span>';
    $html .= '</div>';
    $html .= '<div class="progress-bar recommended"><div class="progress-fill" style="width: ' . $recommended_percentage . '%"></div></div>';
    $html .= '</div>';

    $html .= '</div>';
    return $html;
  }

}
