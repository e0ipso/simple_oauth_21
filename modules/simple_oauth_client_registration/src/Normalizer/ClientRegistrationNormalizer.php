<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Normalizer;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface;
use Drupal\simple_oauth_client_registration\Service\ClientMetadataMapper;
use Drupal\simple_oauth_client_registration\Dto\ClientRegistration;
use Drupal\simple_oauth_client_registration\Service\RegistrationTokenService;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Normalizer for ClientRegistration DTOs and Consumer entities.
 *
 * Handles serialization/deserialization according to RFC 7591.
 */
final class ClientRegistrationNormalizer implements NormalizerInterface, DenormalizerInterface {

  /**
   * Constructs the registration normalizer.
   *
   * @param \Drupal\simple_oauth_client_registration\Service\RegistrationTokenService $tokenService
   *   The registration token service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface|null $grantManager
   *   The installed grant capabilities, when supplied by the container.
   */
  public function __construct(
    private readonly RegistrationTokenService $tokenService,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ?Oauth2GrantManagerInterface $grantManager = NULL,
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
    // Preserve nested JSON objects so they cannot masquerade as empty lists.
    if ($data instanceof \stdClass) {
      $data = (array) $data;
    }
    // Validate required data structure.
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid request body');
    }

    // Validate client metadata according to RFC 7591.
    $this->validateClientMetadata($data);

    // Create DTO from validated data.
    $registration = ClientRegistration::fromArray($data);
    try {
      $registration->validateRedirectUris();
    }
    catch (\InvalidArgumentException $e) {
      throw new BadRequestHttpException($e->getMessage(), $e);
    }
    return $registration;
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
    $metadata = (new ClientMetadataMapper($this->fileUrlGenerator))->map($consumer);

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
   * Validates client metadata according to RFC 7591.
   *
   * @param array $metadata
   *   The client metadata to validate.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If validation fails.
   */
  private function validateClientMetadata(array $metadata): void {
    // Check shapes before validation, interpolation or typed DTO construction.
    $string_fields = [
      'client_name', 'scope', 'client_uri', 'logo_uri', 'tos_uri', 'policy_uri',
      'jwks_uri', 'software_id', 'software_version', 'application_type',
      'token_endpoint_auth_method', 'client_id', 'client_secret',
      'registration_access_token', 'registration_client_uri',
    ];
    foreach ($string_fields as $field) {
      if (array_key_exists($field, $metadata) && !is_string($metadata[$field])) {
        throw new BadRequestHttpException($field . ' must be a string');
      }
    }

    foreach (['redirect_uris', 'contacts', 'grant_types', 'response_types'] as $field) {
      if (!array_key_exists($field, $metadata)) {
        continue;
      }
      if (!is_array($metadata[$field]) || !array_is_list($metadata[$field])) {
        throw new BadRequestHttpException($field . ' must be a list of strings');
      }
      foreach ($metadata[$field] as $value) {
        if (!is_string($value)) {
          throw new BadRequestHttpException($field . ' must contain only strings');
        }
      }
    }

    foreach (['client_id_issued_at', 'client_secret_expires_at'] as $field) {
      if (array_key_exists($field, $metadata) && !is_int($metadata[$field])) {
        throw new BadRequestHttpException($field . ' must be an integer');
      }
    }

    // Validate redirect URIs if provided.
    if (isset($metadata['redirect_uris'])) {
      if (!is_array($metadata['redirect_uris']) || empty($metadata['redirect_uris'])) {
        throw new BadRequestHttpException('redirect_uris must be a non-empty array');
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
      if ($this->grantManager !== NULL) {
        $valid_grant_types = array_unique(array_merge($valid_grant_types, array_keys($this->grantManager->getDefinitions())));
      }
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
