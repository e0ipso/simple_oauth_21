<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
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

    // Overall status summary section.
    $build['dashboard']['summary'] = $this->buildSummarySection($compliance_status);

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

    $status_key = $overall_status['status'] ?? 'non_compliant';
    $status_class = $status_classes[$status_key] ?? 'status-non-compliant';
    $status_icon = $status_icons[$status_key] ?? '❌';

    $section = [
      '#type' => 'details',
      '#title' => $this->t('Overall Compliance Status'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['compliance-section', 'summary-section', $status_class]],
    ];

    $section['status'] = [
      '#type' => 'markup',
      '#markup' => '<div class="compliance-summary">' .
      '<div class="status-indicator">' .
      '<span class="status-icon">' . $status_icon . '</span>' .
      '<h3>' . $this->getStatusTitle($status_key) . '</h3>' .
      '</div>' .
      '<p class="status-message">' . ($summary['message'] ?? '') . '</p>' .
      '</div>',
    ];

    // Score breakdown.
    if (!empty($overall_status)) {
      $section['scores'] = [
        '#type' => 'markup',
        '#markup' => $this->buildScoreBreakdown($overall_status),
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
    // Note: When recommended items are not configured, their status is 'recommended' not 'non_compliant'.
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

    if ($route_name && $link_text) {
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

    // Analyze which specific modules need configuration based on the actual recommendations.
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

    // Only show Action Items section if there are critical issues, since recommendations
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

    // Native apps settings (for recommendations) - enhanced submodule detection.
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
   * Builds the score breakdown markup.
   *
   * @param array $overall_status
   *   The overall status data with scores.
   *
   * @return string
   *   HTML markup for score breakdown.
   */
  private function buildScoreBreakdown(array $overall_status): string {
    $mandatory = $overall_status['mandatory_score'] ?? [];
    $required = $overall_status['required_score'] ?? [];
    $recommended = $overall_status['recommended_score'] ?? [];

    return '<div class="score-breakdown">' .
      '<div class="score-item mandatory">' .
      '<span class="score-label">' . $this->t('Core Requirements') . '</span>' .
      '<span class="score-value">' . ($mandatory['compliant'] ?? 0) . '/' . ($mandatory['total'] ?? 0) . '</span>' .
      '<span class="score-percentage">(' . ($mandatory['percentage'] ?? 0) . '%)</span>' .
      '</div>' .
      '<div class="score-item required">' .
      '<span class="score-label">' . $this->t('Server Metadata') . '</span>' .
      '<span class="score-value">' . ($required['compliant'] ?? 0) . '/' . ($required['total'] ?? 0) . '</span>' .
      '<span class="score-percentage">(' . ($required['percentage'] ?? 0) . '%)</span>' .
      '</div>' .
      '<div class="score-item recommended">' .
      '<span class="score-label">' . $this->t('Best Practices') . '</span>' .
      '<span class="score-value">' . ($recommended['compliant'] ?? 0) . '/' . ($recommended['total'] ?? 0) . '</span>' .
      '<span class="score-percentage">(' . ($recommended['percentage'] ?? 0) . '%)</span>' .
      '</div>' .
      '</div>';
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

}
