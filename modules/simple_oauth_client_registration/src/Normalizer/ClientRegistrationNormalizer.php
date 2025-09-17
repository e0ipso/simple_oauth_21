<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Normalizer;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationService;
use Drupal\simple_oauth_client_registration\Service\RegistrationTokenService;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Normalizer for ClientRegistration DTOs and Consumer entities.
 *
 * Handles serialization/deserialization according to RFC 7591.
 */
class ClientRegistrationNormalizer implements NormalizerInterface, DenormalizerInterface {

  /**
   * Constructor.
   */
  public function __construct(
    private readonly ClientRegistrationService $registrationService,
    private readonly RegistrationTokenService $tokenService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function normalize($object, ?string $format = NULL, array $context = []): array {
    if ($object instanceof ClientRegistration) {
      // Normalize DTO to array.
      return $object->toArray();
    }

    if ($object instanceof ConsumerInterface) {
      // Normalize Consumer entity to ClientRegistration array.
      return $this->normalizeConsumer($object, $context);
    }

    throw new \InvalidArgumentException('Expected ClientRegistration or ConsumerInterface');
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, string $type, ?string $format = NULL, array $context = []): ClientRegistration {
    // Validate required data structure.
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid request body');
    }

    // Validate client metadata according to RFC 7591.
    $this->validateClientMetadata($data);

    // Create DTO from validated data.
    return ClientRegistration::fromArray($data);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsNormalization($data, ?string $format = NULL, array $context = []): bool {
    return $data instanceof ClientRegistration || $data instanceof ConsumerInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDenormalization($data, string $type, ?string $format = NULL, array $context = []): bool {
    return $type === ClientRegistration::class;
  }

  /**
   * Normalizes a Consumer entity to client metadata array.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer entity.
   * @param array $context
   *   Normalization context.
   *
   * @return array
   *   The normalized client metadata.
   */
  private function normalizeConsumer(ConsumerInterface $consumer, array $context = []): array {
    $metadata = [
      'client_id' => $consumer->getClientId(),
      'client_name' => $consumer->label(),
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

    // Get redirect URIs.
    if (!$consumer->get('redirect')->isEmpty()) {
      foreach ($consumer->get('redirect') as $redirect) {
        $metadata['redirect_uris'][] = $redirect->value;
      }
    }

    // Get contacts.
    if (!$consumer->get('contacts')->isEmpty()) {
      foreach ($consumer->get('contacts') as $contact) {
        $metadata['contacts'][] = $contact->value;
      }
    }

    // Get grant types.
    if (!$consumer->get('grant_types')->isEmpty()) {
      foreach ($consumer->get('grant_types') as $grant_type) {
        $metadata['grant_types'][] = $grant_type->value;
      }
    }

    // Add response metadata if this is a registration response.
    if (!empty($context['include_registration_response'])) {
      $client_id = $consumer->getClientId();

      // Generate registration access token.
      $registration_access_token = $this->tokenService->generateRegistrationAccessToken($client_id);

      // Build registration client URI.
      $registration_client_uri = Url::fromRoute('simple_oauth_client_registration.manage', [
        'client_id' => $client_id,
      ], ['absolute' => TRUE])->toString();

      $metadata['registration_access_token'] = $registration_access_token;
      $metadata['registration_client_uri'] = $registration_client_uri;
      $metadata['client_id_issued_at'] = time();

      // Include client secret if available in context.
      if (!empty($context['client_secret'])) {
        $metadata['client_secret'] = $context['client_secret'];
        // Never expires.
        $metadata['client_secret_expires_at'] = 0;
      }
    }

    // Remove empty fields.
    return array_filter($metadata, function ($value) {
      return $value !== '' && $value !== [];
    });
  }

  /**
   * Gets the logo URI for a consumer.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer entity.
   *
   * @return string
   *   The logo URI or empty string.
   */
  private function getLogoUri(ConsumerInterface $consumer): string {
    // First, check if there's an uploaded image file.
    if (!$consumer->get('image')->isEmpty()) {
      $image_field = $consumer->get('image')->first();
      if ($image_field && $image_field->entity) {
        $file = $image_field->entity;
        // Generate absolute URL for the image file.
        $file_uri = $file->getFileUri();
        return \Drupal::service('file_url_generator')->generateAbsoluteString($file_uri);
      }
    }

    // Fallback to logo_uri field if no image uploaded.
    return $consumer->get('logo_uri')->value ?? '';
  }

  /**
   * Validates client metadata according to RFC 7591.
   *
   * @param array $metadata
   *   The client metadata to validate.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If validation fails.
   */
  private function validateClientMetadata(array $metadata): void {
    // Validate redirect URIs if provided.
    if (isset($metadata['redirect_uris'])) {
      if (!is_array($metadata['redirect_uris']) || empty($metadata['redirect_uris'])) {
        throw new BadRequestHttpException('redirect_uris must be a non-empty array');
      }

      foreach ($metadata['redirect_uris'] as $uri) {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
          throw new BadRequestHttpException('Invalid redirect URI: ' . $uri);
        }
      }
    }

    // Validate URI fields.
    $uri_fields = ['client_uri', 'logo_uri', 'tos_uri', 'policy_uri', 'jwks_uri'];
    foreach ($uri_fields as $field) {
      if (isset($metadata[$field]) && !empty($metadata[$field])) {
        if (!filter_var($metadata[$field], FILTER_VALIDATE_URL)) {
          throw new BadRequestHttpException('Invalid URI for ' . $field . ': ' . $metadata[$field]);
        }
      }
    }

    // Validate contacts array.
    if (isset($metadata['contacts'])) {
      if (!is_array($metadata['contacts'])) {
        throw new BadRequestHttpException('contacts must be an array');
      }

      foreach ($metadata['contacts'] as $contact) {
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
          throw new BadRequestHttpException('Invalid email in contacts: ' . $contact);
        }
      }
    }

    // Validate string fields.
    $string_fields = ['client_name', 'software_id', 'software_version'];
    foreach ($string_fields as $field) {
      if (isset($metadata[$field]) && !is_string($metadata[$field])) {
        throw new BadRequestHttpException($field . ' must be a string');
      }
    }

    // Validate grant types.
    if (isset($metadata['grant_types'])) {
      if (!is_array($metadata['grant_types'])) {
        throw new BadRequestHttpException('grant_types must be an array');
      }
      $valid_grant_types = [
        'authorization_code',
        'implicit',
        'password',
        'client_credentials',
        'refresh_token',
        'urn:ietf:params:oauth:grant-type:device_code',
      ];
      foreach ($metadata['grant_types'] as $grant_type) {
        if (!in_array($grant_type, $valid_grant_types, TRUE)) {
          throw new BadRequestHttpException('Invalid grant type: ' . $grant_type);
        }
      }
    }

    // Validate response types.
    if (isset($metadata['response_types'])) {
      if (!is_array($metadata['response_types'])) {
        throw new BadRequestHttpException('response_types must be an array');
      }
      $valid_response_types = ['code', 'token', 'id_token'];
      foreach ($metadata['response_types'] as $response_type) {
        // Response types can be space-separated combinations.
        $types = explode(' ', $response_type);
        foreach ($types as $type) {
          if (!in_array($type, $valid_response_types, TRUE)) {
            throw new BadRequestHttpException('Invalid response type: ' . $type);
          }
        }
      }
    }

    // Validate token endpoint auth method.
    if (isset($metadata['token_endpoint_auth_method'])) {
      $valid_methods = [
        'none',
        'client_secret_basic',
        'client_secret_post',
        'client_secret_jwt',
        'private_key_jwt',
      ];
      if (!in_array($metadata['token_endpoint_auth_method'], $valid_methods, TRUE)) {
        throw new BadRequestHttpException('Invalid token_endpoint_auth_method: ' . $metadata['token_endpoint_auth_method']);
      }
    }

    // Validate application type.
    if (isset($metadata['application_type'])) {
      if (!in_array($metadata['application_type'], ['web', 'native'], TRUE)) {
        throw new BadRequestHttpException('Invalid application_type: ' . $metadata['application_type']);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return [
      ClientRegistration::class => TRUE,
      ConsumerInterface::class => TRUE,
    ];
  }

}
