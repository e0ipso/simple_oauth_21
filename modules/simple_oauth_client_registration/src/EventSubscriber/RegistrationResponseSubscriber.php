<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prevents storage of client registration and management responses.
 */
final class RegistrationResponseSubscriber implements EventSubscriberInterface {

  /**
   * Applies the credential response policy after core finishes cache headers.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!in_array($event->getRequest()->attributes->get('_route'), [
      'simple_oauth_client_registration.register',
      'simple_oauth_client_registration.manage',
    ], TRUE)) {
      return;
    }

    $response = $event->getResponse();
    if ($response instanceof CacheableResponseInterface) {
      $response->getCacheableMetadata()->setCacheMaxAge(0);
    }
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Core FinishResponseSubscriber::onRespond() runs at priority zero.
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

}
