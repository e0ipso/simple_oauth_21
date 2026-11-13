<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Service;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;

/**
 * Maps shared consumer metadata without issuing credentials or loading scopes.
 */
final class ClientMetadataMapper {

  /**
   * Constructs the metadata mapper.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator for uploaded logos.
   * @param \Drupal\simple_oauth_client_registration\Service\TokenEndpointAuthMethodResolverInterface|null $authMethodResolver
   *   The effective authentication method resolver, or the built-in profile.
   */
  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ?TokenEndpointAuthMethodResolverInterface $authMethodResolver = NULL,
  ) {}

  /**
   * Projects the metadata shared by serialization and management responses.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer to project.
   * @param \Drupal\simple_oauth_client_registration\Dto\ClientRegistration|null $prepared
   *   An explicitly prepared credential envelope for this consumer. Other DTO
   *   metadata is ignored; scope selection remains the caller's responsibility.
   *
   * @return array
   *   Nonempty metadata and supplied credentials, without scope selection.
   *
   * @throws \InvalidArgumentException
   *   If the prepared credentials are incomplete or belong to another client.
   */
  public function map(ConsumerInterface $consumer, ?ClientRegistration $prepared = NULL): array {
    if ($prepared !== NULL && ($prepared->clientId !== $consumer->getClientId() ||
      $prepared->registrationAccessToken === NULL || $prepared->registrationAccessToken === '' ||
      $prepared->registrationClientUri === NULL || $prepared->registrationClientUri === '' ||
      $prepared->clientIdIssuedAt === NULL)) {
      throw new \InvalidArgumentException('Prepared registration credentials must be complete and match the consumer');
    }
    $metadata = [
      'client_id' => $consumer->getClientId(),
      'client_name' => $consumer->label(),
      'token_endpoint_auth_method' => ($this->authMethodResolver ?? new TokenEndpointAuthMethodResolver())->resolve($consumer),
      'client_uri' => $consumer->get('client_uri')->value ?? '',
      'logo_uri' => $this->getLogoUri($consumer),
      'tos_uri' => $consumer->get('tos_uri')->value ?? '',
      'policy_uri' => $consumer->get('policy_uri')->value ?? '',
      'jwks_uri' => $consumer->get('jwks_uri')->value ?? '',
      'software_id' => $consumer->get('software_id')->value ?? '',
      'software_version' => $consumer->get('software_version')->value ?? '',
      'redirect_uris' => [],
      'contacts' => [],
      'grant_types' => [],
    ];

    // Report only explicit classifications retained by the installed control.
    if ($consumer->hasField('native_app_override')) {
      $application_type = $consumer->get('native_app_override')->value;
      if (in_array($application_type, ['native', 'web'], TRUE)) {
        $metadata['application_type'] = $application_type;
      }
    }

    // Get redirect URIs.
    if (!$consumer->get('redirect')->isEmpty()) {
      foreach ($consumer->get('redirect') as $redirect) {
        $metadata['redirect_uris'][] = $redirect->get('value')->getValue();
      }
    }

    // Get contacts.
    if (!$consumer->get('contacts')->isEmpty()) {
      foreach ($consumer->get('contacts') as $contact) {
        $metadata['contacts'][] = $contact->get('value')->getValue();
      }
    }

    // Get grant types.
    if (!$consumer->get('grant_types')->isEmpty()) {
      foreach ($consumer->get('grant_types') as $grant_type) {
        $metadata['grant_types'][] = $grant_type->get('value')->getValue();
      }
    }

    if ($prepared !== NULL) {
      $metadata['registration_access_token'] = $prepared->registrationAccessToken;
      $metadata['registration_client_uri'] = $prepared->registrationClientUri;
      $metadata['client_id_issued_at'] = $prepared->clientIdIssuedAt;
      if (!in_array($prepared->clientSecret, [NULL, '', '0'], TRUE)) {
        $metadata['client_secret'] = $prepared->clientSecret;
        $metadata['client_secret_expires_at'] = $prepared->clientSecretExpiresAt ?? 0;
      }
    }

    return array_filter($metadata, static fn($value): bool => $value !== '' && $value !== []);
  }

  /**
   * Gets the logo URI for a consumer, prioritizing image field over logo_uri.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer entity.
   *
   * @return string
   *   The logo URI or empty string if none available.
   */
  private function getLogoUri(ConsumerInterface $consumer): string {
    // First, check if there's an uploaded image file.
    if (!$consumer->get('image')->isEmpty()) {
      $image_field = $consumer->get('image')->first();
      $file = $image_field?->get('entity')->getValue();
      if ($file instanceof FileInterface) {
        // Generate absolute URL for the image file.
        $file_uri = $file->getFileUri();
        return $this->fileUrlGenerator->generateAbsoluteString($file_uri);
      }
    }

    // Fallback to logo_uri field if no image uploaded.
    return $consumer->get('logo_uri')->value ?? '';
  }

}
