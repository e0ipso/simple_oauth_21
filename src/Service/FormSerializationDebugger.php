<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_21\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service for debugging form serialization issues.
 *
 * This service provides comprehensive debugging tools to identify
 * non-serializable closures and other problematic elements in form arrays
 * that cause AJAX serialization failures.
 */
final class FormSerializationDebugger {

  /**
   * Constructor with dependency injection.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel for simple_oauth_21.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    private readonly LoggerChannelInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Logs form alter hook execution.
   *
   * @param string $formId
   *   The form ID being altered.
   * @param string $hookName
   *   The name of the hook being executed.
   * @param array $context
   *   Additional context information.
   */
  public function logFormAlterHook(string $formId, string $hookName, array $context = []): void {
    if (!$this->isDebugEnabled()) {
      return;
    }

    try {
      $this->logger->debug('Form alter hook executed: @hook for form @form_id', [
        '@hook' => $hookName,
        '@form_id' => $formId,
        'context' => $context,
        'backtrace' => $this->getSimplifiedBacktrace(),
      ]);
    }
    catch (\Exception $e) {
      // Prevent debugging from breaking form functionality.
      $this->logger->error('Error in form alter hook logging: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Inspects form state for non-serializable elements.
   *
   * @param array $form
   *   The form array to inspect.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The form state object.
   * @param string $formId
   *   The form ID for context.
   *
   * @return array
   *   Array of detected issues with their locations and types.
   */
  public function inspectFormState(array $form, $formState, string $formId): array {
    if (!$this->isDebugEnabled()) {
      return [];
    }

    $issues = [];

    try {
      // Detect closures in form array.
      $formClosures = $this->detectClosures($form, "form[{$formId}]");
      $issues = array_merge($issues, $formClosures);

      // Check form state for non-serializable elements.
      $formStateIssues = $this->inspectFormStateObject($formState, $formId);
      $issues = array_merge($issues, $formStateIssues);

      // Log the inspection results.
      if (!empty($issues)) {
        $this->logger->warning('Form serialization issues detected in @form_id: @count issues found', [
          '@form_id' => $formId,
          '@count' => count($issues),
          'issues' => $issues,
        ]);
      }
      else {
        $this->logger->debug('No serialization issues detected in @form_id', [
          '@form_id' => $formId,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error during form state inspection: @message', [
        '@message' => $e->getMessage(),
        'form_id' => $formId,
      ]);
    }

    return $issues;
  }

  /**
   * Recursively detects closures in form arrays.
   *
   * @param mixed $element
   *   The element to inspect (can be array, object, or scalar).
   * @param string $path
   *   The current path for tracking location.
   * @param int $depth
   *   Current recursion depth to prevent infinite loops.
   *
   * @return array
   *   Array of detected closure issues.
   */
  public function detectClosures($element, string $path = 'root', int $depth = 0): array {
    // Prevent infinite recursion.
    if ($depth > 10) {
      return [
        [
          'type' => 'max_depth_exceeded',
          'path' => $path,
          'message' => 'Maximum inspection depth exceeded',
        ],
      ];
    }

    $issues = [];

    try {
      // Check if the element itself is a closure.
      if (is_callable($element) && is_object($element)) {
        $issues[] = [
          'type' => 'closure',
          'path' => $path,
          'message' => 'Closure detected',
          'class' => get_class($element),
        ];
      }

      // Check if element is an object that might not be serializable.
      if (is_object($element) && !($element instanceof \Serializable) &&
          !method_exists($element, '__serialize')) {
        $issues[] = [
          'type' => 'non_serializable_object',
          'path' => $path,
          'message' => 'Non-serializable object detected',
          'class' => get_class($element),
        ];
      }

      // Recursively check arrays.
      if (is_array($element)) {
        foreach ($element as $key => $value) {
          $currentPath = "{$path}[{$key}]";
          $subIssues = $this->detectClosures($value, $currentPath, $depth + 1);
          $issues = array_merge($issues, $subIssues);
        }
      }
    }
    catch (\Exception $e) {
      $issues[] = [
        'type' => 'inspection_error',
        'path' => $path,
        'message' => "Error during inspection: {$e->getMessage()}",
      ];
    }

    return $issues;
  }

  /**
   * Generates a structured analysis report.
   *
   * @param array $issues
   *   Array of detected issues.
   * @param string $formId
   *   The form ID for context.
   *
   * @return array
   *   Structured report data.
   */
  public function generateReport(array $issues, string $formId): array {
    $report = [
      'form_id' => $formId,
      'timestamp' => date('Y-m-d H:i:s'),
      'total_issues' => count($issues),
      'issues_by_type' => [],
      'critical_paths' => [],
      'recommendations' => [],
    ];

    // Group issues by type.
    $issuesByType = array_reduce($issues, function ($carry, $issue) {
      $type = $issue['type'] ?? 'unknown';
      $carry[$type] = ($carry[$type] ?? 0) + 1;
      return $carry;
    }, []);

    $report['issues_by_type'] = $issuesByType;

    // Identify critical paths (AJAX callbacks).
    $criticalPaths = array_filter($issues, function ($issue) {
      return str_contains($issue['path'] ?? '', '#ajax') ||
             str_contains($issue['path'] ?? '', 'callback');
    });

    $report['critical_paths'] = array_map(function ($issue) {
      return $issue['path'];
    }, $criticalPaths);

    // Generate recommendations.
    $report['recommendations'] = $this->generateRecommendations($issuesByType);

    if ($this->isDebugEnabled()) {
      $this->logger->info('Form serialization analysis report generated for @form_id', [
        '@form_id' => $formId,
        'report' => $report,
      ]);
    }

    return $report;
  }

  /**
   * Checks if debugging is enabled.
   *
   * @return bool
   *   TRUE if debugging is enabled, FALSE otherwise.
   */
  private function isDebugEnabled(): bool {
    try {
      $config = $this->configFactory->get('simple_oauth_21.debug');
      return $config->get('form_serialization_debugging') ?? FALSE;
    }
    catch (\Exception $e) {
      // If config doesn't exist or there's an error, assume debugging disabled.
      return FALSE;
    }
  }

  /**
   * Inspects form state object for serialization issues.
   *
   * @param mixed $formState
   *   The form state object.
   * @param string $formId
   *   The form ID for context.
   *
   * @return array
   *   Array of detected issues in form state.
   */
  private function inspectFormStateObject($formState, string $formId): array {
    $issues = [];

    try {
      if (!is_object($formState)) {
        return $issues;
      }

      // Check if form state has any non-serializable callbacks.
      $reflection = new \ReflectionClass($formState);
      $properties = $reflection->getProperties();

      foreach ($properties as $property) {
        $property->setAccessible(TRUE);
        try {
          $value = $property->getValue($formState);
          if (is_callable($value) && is_object($value)) {
            $issues[] = [
              'type' => 'form_state_closure',
              'path' => "form_state->{$property->getName()}",
              'message' => 'Closure in form state property',
              'property' => $property->getName(),
            ];
          }
        }
        catch (\Exception $e) {
          // Some properties might not be accessible, that's okay.
        }
      }
    }
    catch (\Exception $e) {
      $issues[] = [
        'type' => 'form_state_inspection_error',
        'path' => 'form_state',
        'message' => "Error inspecting form state: {$e->getMessage()}",
      ];
    }

    return $issues;
  }

  /**
   * Gets a simplified backtrace for debugging.
   *
   * @return array
   *   Simplified backtrace information.
   */
  private function getSimplifiedBacktrace(): array {
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

    return array_map(function ($frame) {
      return [
        'function' => $frame['function'] ?? 'unknown',
        'class' => $frame['class'] ?? NULL,
        'file' => basename($frame['file'] ?? 'unknown'),
        'line' => $frame['line'] ?? 0,
      ];
    }, $backtrace);
  }

  /**
   * Generates recommendations based on detected issue types.
   *
   * @param array $issuesByType
   *   Issues grouped by type.
   *
   * @return array
   *   Array of recommendation strings.
   */
  private function generateRecommendations(array $issuesByType): array {
    $recommendations = [];

    if (isset($issuesByType['closure'])) {
      $recommendations[] = 'Replace closure callbacks with method references or static callbacks';
      $recommendations[] = 'Consider using dependency injection for service callbacks';
    }

    if (isset($issuesByType['non_serializable_object'])) {
      $recommendations[] = 'Implement __serialize()/__unserialize() methods for custom objects';
      $recommendations[] = 'Consider using serializable data transfer objects';
    }

    if (isset($issuesByType['form_state_closure'])) {
      $recommendations[] = 'Review form state manipulation and avoid storing closures';
      $recommendations[] = 'Use form alter hooks instead of direct form state manipulation';
    }

    if (empty($recommendations)) {
      $recommendations[] = 'No specific recommendations - form appears to be serialization-safe';
    }

    return $recommendations;
  }

}
