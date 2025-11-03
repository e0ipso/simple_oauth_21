<?php

declare(strict_types=1);

namespace Drupal\resource_metadata_event_test\EventSubscriber;

use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent;
use Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Test event subscriber for resource metadata event system testing.
 *
 * Provides custom metadata fields and overrides for functional testing
 * of the resource metadata event system.
 */
final class ResourceMetadataEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ResourceMetadataEvents::BUILD => [
        ['onBuildMetadata', 100],
      ],
    ];
  }

  /**
   * Responds to resource metadata build events.
   *
   * Adds custom fields and overrides configured values for testing.
   *
   * @param \Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvent $event
   *   The resource metadata event.
   */
  public function onBuildMetadata(ResourceMetadataEvent $event): void {
    // Add custom fields for testing event dispatch.
    $event->addMetadataField('custom_resource_capability', 'test_capability');
    $event->addMetadataField('custom_bearer_methods', ['header']);
    $event->addMetadataField('test_metadata_field', 'test_value_12345');

    // Override configured fields for testing field override.
    // Check if fields exist in the current metadata (after config was applied).
    if (isset($event->metadata['resource_documentation']) && !empty($event->metadata['resource_documentation'])) {
      $event->addMetadataField('resource_documentation', 'https://override.example.com/docs');
    }
    if (isset($event->metadata['resource_policy_uri']) && !empty($event->metadata['resource_policy_uri'])) {
      $event->addMetadataField('resource_policy_uri', 'https://override.example.com/policy');
    }

    // Add multiple custom fields for RFC compliance testing.
    $event->addMetadataField('custom_field_1', 'value1');
    $event->addMetadataField('custom_field_2', ['array', 'value']);
    $event->addMetadataField('custom_field_3', 12345);
    $event->addMetadataField('custom_resource_info', 'additional_info');

    // Override bearer methods to test override capability.
    $event->addMetadataField('bearer_methods_supported', ['header', 'query']);
  }

}
