<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_oauth_21\Service\OAuth21ComplianceService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OAuth 2.1 compliance dashboard controller.
 *
 * Provides a simplified read-only dashboard that displays OAuth 2.1
 * RFC implementation status using Single Directory Components.
 */
final class OAuth21ComplianceController extends ControllerBase {

  /**
   * Constructs an OAuth21ComplianceController object.
   *
   * @param \Drupal\simple_oauth_21\Service\OAuth21ComplianceService $complianceService
   *   The OAuth 2.1 compliance service.
   */
  public function __construct(
    private readonly OAuth21ComplianceService $complianceService,
  ) {}

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
    try {
      $rfc_data = $this->complianceService->getRfcComplianceStatus();
    }
    catch (\Exception $e) {
      return [
        '#type' => 'component',
        '#component' => 'simple_oauth_21:compliance-tip',
        '#props' => [
          'message' => $this->t('Unable to load compliance status. Please check your configuration.'),
          'type' => 'warning',
        ],
      ];
    }

    $rfc_array = $this->prepareRfcDataForComponent($rfc_data);

    return [
      '#type' => 'component',
      '#component' => 'simple_oauth_21:rfc-matrix',
      '#props' => [
        'rfcs' => $rfc_array,
        'title' => $this->t('OAuth 2.1 RFC Implementation Status'),
      ],
    ];
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
      'rfc_7636' => 'PKCE (Proof Key for Code Exchange)',
      'rfc_8414' => 'OAuth Server Metadata',
      'rfc_8252' => 'OAuth for Native Apps',
      'rfc_8628' => 'Device Authorization Grant',
      'rfc_7591' => 'Dynamic Client Registration',
    ];

    $rfc_descriptions = [
      'rfc_7636' => 'S256 challenge method, mandatory enforcement',
      'rfc_8414' => 'Well-known discovery endpoint',
      'rfc_8252' => 'Custom URI schemes, loopback redirects, WebView detection',
      'rfc_8628' => 'Device flow for input-constrained devices',
      'rfc_7591' => 'Full CRUD operations, metadata support',
    ];

    $rfc_urls = [
      'rfc_7636' => 'https://datatracker.ietf.org/doc/html/rfc7636',
      'rfc_8414' => 'https://datatracker.ietf.org/doc/html/rfc8414',
      'rfc_8252' => 'https://datatracker.ietf.org/doc/html/rfc8252',
      'rfc_8628' => 'https://datatracker.ietf.org/doc/html/rfc8628',
      'rfc_7591' => 'https://datatracker.ietf.org/doc/html/rfc7591',
    ];

    $config_routes = [
      'simple_oauth_pkce' => 'simple_oauth_pkce.settings',
      'simple_oauth_server_metadata' => 'simple_oauth_server_metadata.settings',
      'simple_oauth_native_apps' => 'simple_oauth_native_apps.settings',
      // Note: device_flow and client_registration use different routing
      // patterns.
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
        'recommendation' => $data['recommendation'] ?? '',
      ];
    }

    return $prepared;
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
