<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\simple_oauth_21\Service\OAuth21ComplianceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OAuth 2.1 compliance dashboard controller.
 *
 * Provides a simplified read-only dashboard that displays OAuth 2.1
 * RFC implementation status using the available Drupal render elements.
 */
final class OAuth21ComplianceController extends ControllerBase {

  /**
   * Constructs an OAuth21ComplianceController object.
   *
   * @param \Drupal\simple_oauth_21\Service\OAuth21ComplianceService $complianceService
   *   The OAuth 2.1 compliance service.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $elementInfo
   *   The render element manager.
   */
  public function __construct(
    private readonly OAuth21ComplianceService $complianceService,
    private readonly ElementInfoManagerInterface $elementInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('simple_oauth_21.compliance_service'),
      $container->get('plugin.manager.element_info'),
    );
  }

  /**
   * Displays the OAuth 2.1 compliance dashboard.
   *
   * @return array
   *   A render array for the compliance dashboard.
   */
  public function display(): array {
    try {
      $rfc_data = $this->complianceService->getRfcComplianceStatus();
    }
    catch (\Exception $e) {
      $message = $this->t('Unable to load compliance status. Please check your configuration.');
      if ($this->hasComponentRenderer()) {
        return [
          '#type' => 'component',
          '#component' => 'simple_oauth_21:compliance-tip',
          '#props' => ['message' => $message, 'type' => 'warning'],
          '#cache' => ['max-age' => 0],
        ];
      }
      return [
        '#type' => 'container',
        'message' => ['#markup' => $message],
        '#cache' => ['max-age' => 0],
      ];
    }

    $rfcs = $this->prepareRfcDataForComponent($rfc_data);
    $title = $this->t('OAuth 2.1 RFC Implementation Status');
    if ($this->hasComponentRenderer()) {
      $build = [
        '#type' => 'component',
        '#component' => 'simple_oauth_21:rfc-matrix',
        '#props' => ['rfcs' => $rfcs, 'title' => $title],
      ];
    }
    else {
      $rows = [];
      foreach ($rfcs as $rfc) {
        $status = match ($rfc['service_status']) {
          'configured' => $this->t('Configured'),
          'needs_attention' => $this->t('Needs attention'),
          'not_available' => $this->t('Not available'),
          default => $rfc['service_status'],
        };
        $rows[] = [
          ['data' => ['#plain_text' => (string) $rfc['title']]],
          ['data' => ['#plain_text' => (string) $rfc['description']]],
          ['data' => ['#plain_text' => (string) $status]],
          ['data' => ['#plain_text' => $rfc['module']]],
          ['data' => ['#plain_text' => $rfc['recommendation']]],
        ];
      }
      $build = [
        '#type' => 'container',
        'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $title],
        'matrix' => [
          '#type' => 'table',
          '#header' => [
            $this->t('RFC'), $this->t('Description'), $this->t('Status'),
            $this->t('Module'), $this->t('Recommendation'),
          ],
          '#rows' => $rows,
        ],
      ];
    }
    CacheableMetadata::createFromObject($this->complianceService)->applyTo($build);
    return $build;
  }

  /**
   * Checks the render element capability on the active installation.
   *
   * @return bool
   *   Whether Drupal can render single directory components.
   */
  private function hasComponentRenderer(): bool {
    return $this->elementInfo->hasDefinition('component');
  }

  /**
   * Prepares RFC data for the RFC matrix component.
   *
   * @param array $rfc_data
   *   Raw RFC data from the service.
   *
   * @return array
   *   Prepared RFC data for component props.
   */
  private function prepareRfcDataForComponent(array $rfc_data): array {
    $prepared = [];

    $rfc_titles = [
      'rfc_7636' => $this->t('PKCE (Proof Key for Code Exchange)'),
      'rfc_8414' => $this->t('OAuth Server Metadata'),
      'rfc_8252' => $this->t('OAuth for Native Apps'),
      'rfc_8628' => $this->t('Device Authorization Grant'),
      'rfc_7591' => $this->t('Dynamic Client Registration'),
      'rfc_7662' => $this->t('OAuth 2.0 Token Introspection'),
    ];

    $rfc_descriptions = [
      'rfc_7636' => $this->t('S256 challenge method, mandatory enforcement'),
      'rfc_8414' => $this->t('Well-known discovery endpoint'),
      'rfc_8252' => $this->t('Custom URI schemes, loopback redirects, WebView detection'),
      'rfc_8628' => $this->t('Device flow for input-constrained devices'),
      'rfc_7591' => $this->t('Full CRUD operations, metadata support'),
      'rfc_7662' => $this->t('Token metadata introspection for resource servers'),
    ];

    $rfc_urls = [
      'rfc_7636' => 'https://datatracker.ietf.org/doc/html/rfc7636',
      'rfc_8414' => 'https://datatracker.ietf.org/doc/html/rfc8414',
      'rfc_8252' => 'https://datatracker.ietf.org/doc/html/rfc8252',
      'rfc_8628' => 'https://datatracker.ietf.org/doc/html/rfc8628',
      'rfc_7591' => 'https://datatracker.ietf.org/doc/html/rfc7591',
      'rfc_7662' => 'https://datatracker.ietf.org/doc/html/rfc7662',
    ];

    $config_routes = [
      'simple_oauth_pkce' => 'simple_oauth_pkce.settings',
      'simple_oauth_server_metadata' => 'simple_oauth_server_metadata.settings',
      'simple_oauth_native_apps' => 'simple_oauth_native_apps.settings',
      'simple_oauth_client_registration' => 'simple_oauth_client_registration.settings',
      // Note: device_flow uses a different routing pattern.
    ];

    foreach ($rfc_data as $rfc_key => $data) {
      $component_status = $this->mapServiceStatusToComponentStatus($data['status']);
      $module_name = $data['module'] ?? '';

      $prepared[] = [
        'id' => $rfc_key,
        'title' => $rfc_titles[$rfc_key] ?? $rfc_key,
        'description' => $rfc_descriptions[$rfc_key] ?? '',
        'status' => $component_status,
        'module' => $module_name,
        'rfc_url' => $rfc_urls[$rfc_key] ?? '',
        'config_route' => $config_routes[$module_name] ?? '',
        'service_status' => $data['status'] ?? 'not_available',
        'recommendation' => $this->translateRecommendation($data['recommendation'] ?? ''),
      ];
    }

    return $prepared;
  }

  /**
   * Translates known recommendations while preserving future service messages.
   *
   * @param string $recommendation
   *   The public service recommendation.
   *
   * @return string
   *   The translated presentation text.
   */
  private function translateRecommendation(string $recommendation): string {
    $known = [
      'PKCE is enabled for OAuth 2.1 compliance' => $this->t('PKCE is enabled for OAuth 2.1 compliance'),
      'Enable PKCE module for OAuth 2.1 compliance' => $this->t('Enable PKCE module for OAuth 2.1 compliance'),
      'Enable server metadata module for better client integration' => $this->t('Enable server metadata module for better client integration'),
      'Server metadata is available for discovery' => $this->t('Server metadata is available for discovery'),
      'Native app security features are enabled' => $this->t('Native app security features are enabled'),
      'Enable native apps module for mobile app security' => $this->t('Enable native apps module for mobile app security'),
      'Device flow is available for constrained devices' => $this->t('Device flow is available for constrained devices'),
      'Enable device flow module for IoT and TV apps' => $this->t('Enable device flow module for IoT and TV apps'),
      'Dynamic client registration is available' => $this->t('Dynamic client registration is available'),
      'Enable client registration module for automated client setup' => $this->t('Enable client registration module for automated client setup'),
      'Token introspection endpoint is available for resource servers' => $this->t('Token introspection endpoint is available for resource servers'),
      'Token introspection endpoint is not implemented' => $this->t('Token introspection endpoint is not implemented'),
      'Server metadata discovery is unavailable; check the metadata module service registration' => $this->t('Server metadata discovery is unavailable; check the metadata module service registration'),
      'Unable to generate server metadata; check OAuth endpoint discovery' => $this->t('Unable to generate server metadata; check OAuth endpoint discovery'),
    ];
    // A known diagnostic contains a semicolon, so match whole messages first.
    if (isset($known[$recommendation])) {
      return (string) $known[$recommendation];
    }
    $parts = explode('; ', $recommendation);
    foreach ($parts as &$part) {
      if (preg_match('/^Discover a valid HTTPS URL for ([a-z_]+) in server metadata$/D', $part, $matches)) {
        $part = (string) $this->t('Discover a valid HTTPS URL for @field in server metadata', ['@field' => $matches[1]]);
      }
      elseif (isset($known[$part])) {
        $part = (string) $known[$part];
      }
    }
    return implode('; ', $parts);
  }

  /**
   * Maps service status values to component-expected status values.
   *
   * @param string $service_status
   *   Status from the compliance service.
   *
   * @return string
   *   Component-compatible status value.
   */
  private function mapServiceStatusToComponentStatus(string $service_status): string {
    return match ($service_status) {
      'configured' => 'enabled',
      // Module is enabled but needs configuration.
      'needs_attention' => 'enabled',
      'not_available' => 'not-installed',
      default => 'not-installed',
    };
  }

}
