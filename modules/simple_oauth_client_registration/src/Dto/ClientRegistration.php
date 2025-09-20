<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Dto;

/**
 * Data Transfer Object for OAuth 2.0 client registration.
 *
 * Represents client metadata according to RFC 7591.
 */
final class ClientRegistration {

  /**
   * Constructor.
   *
   * @param string|null $clientName
   *   Human-readable name of the client.
   * @param array $redirectUris
   *   Array of redirection URIs.
   * @param array $grantTypes
   *   Array of OAuth 2.0 grant types.
   * @param array $responseTypes
   *   Array of OAuth 2.0 response types.
   * @param string|null $tokenEndpointAuthMethod
   *   Client authentication method for the token endpoint.
   * @param string|null $scope
   *   Space-delimited list of scope values.
   * @param string|null $clientUri
   *   URL of the client's home page.
   * @param string|null $logoUri
   *   URL that references a logo for the client.
   * @param array $contacts
   *   Array of email addresses for people responsible for the client.
   * @param string|null $tosUri
   *   URL for the client's terms of service.
   * @param string|null $policyUri
   *   URL for the client's privacy policy.
   * @param string|null $jwksUri
   *   URL for the client's JSON Web Key Set document.
   * @param string|null $softwareId
   *   Unique identifier for the client software.
   * @param string|null $softwareVersion
   *   Version of the client software.
   * @param string|null $applicationType
   *   Type of application (web, native).
   * @param string|null $clientId
   *   Unique client identifier (only set in responses).
   * @param string|null $clientSecret
   *   Client secret (only set in responses for confidential clients).
   * @param string|null $registrationAccessToken
   *   Access token for client configuration endpoint (only in responses).
   * @param string|null $registrationClientUri
   *   URI for client configuration endpoint (only in responses).
   * @param int|null $clientIdIssuedAt
   *   Timestamp when client_id was issued (only in responses).
   * @param int|null $clientSecretExpiresAt
   *   Timestamp when client_secret expires (only in responses).
   */
  public function __construct(
    private readonly ?string $clientName = NULL,
    private readonly array $redirectUris = [],
    private readonly array $grantTypes = [],
    private readonly array $responseTypes = [],
    private readonly ?string $tokenEndpointAuthMethod = NULL,
    private readonly ?string $scope = NULL,
    private readonly ?string $clientUri = NULL,
    private readonly ?string $logoUri = NULL,
    private readonly array $contacts = [],
    private readonly ?string $tosUri = NULL,
    private readonly ?string $policyUri = NULL,
    private readonly ?string $jwksUri = NULL,
    private readonly ?string $softwareId = NULL,
    private readonly ?string $softwareVersion = NULL,
    private readonly ?string $applicationType = NULL,
    private readonly ?string $clientId = NULL,
    private readonly ?string $clientSecret = NULL,
    private readonly ?string $registrationAccessToken = NULL,
    private readonly ?string $registrationClientUri = NULL,
    private readonly ?int $clientIdIssuedAt = NULL,
    private readonly ?int $clientSecretExpiresAt = NULL,
  ) {}

  /**
   * Creates a ClientRegistration from an array.
   *
   * @param array $data
   *   The client registration data.
   *
   * @return self
   *   A new ClientRegistration instance.
   */
  public static function fromArray(array $data): self {
    return new self(
      clientName: $data['client_name'] ?? NULL,
      redirectUris: $data['redirect_uris'] ?? [],
      grantTypes: $data['grant_types'] ?? [],
      responseTypes: $data['response_types'] ?? [],
      tokenEndpointAuthMethod: $data['token_endpoint_auth_method'] ?? NULL,
      scope: $data['scope'] ?? NULL,
      clientUri: $data['client_uri'] ?? NULL,
      logoUri: $data['logo_uri'] ?? NULL,
      contacts: $data['contacts'] ?? [],
      tosUri: $data['tos_uri'] ?? NULL,
      policyUri: $data['policy_uri'] ?? NULL,
      jwksUri: $data['jwks_uri'] ?? NULL,
      softwareId: $data['software_id'] ?? NULL,
      softwareVersion: $data['software_version'] ?? NULL,
      applicationType: $data['application_type'] ?? NULL,
      clientId: $data['client_id'] ?? NULL,
      clientSecret: $data['client_secret'] ?? NULL,
      registrationAccessToken: $data['registration_access_token'] ?? NULL,
      registrationClientUri: $data['registration_client_uri'] ?? NULL,
      clientIdIssuedAt: isset($data['client_id_issued_at']) ? (int) $data['client_id_issued_at'] : NULL,
      clientSecretExpiresAt: isset($data['client_secret_expires_at']) ? (int) $data['client_secret_expires_at'] : NULL,
    );
  }

  /**
   * Converts the ClientRegistration to an array.
   *
   * @return array
   *   The client registration data as an array.
   */
  public function toArray(): array {
    $data = [];

    // Add all non-null values.
    if ($this->clientName !== NULL) {
      $data['client_name'] = $this->clientName;
    }
    if (!empty($this->redirectUris)) {
      $data['redirect_uris'] = $this->redirectUris;
    }
    if (!empty($this->grantTypes)) {
      $data['grant_types'] = $this->grantTypes;
    }
    if (!empty($this->responseTypes)) {
      $data['response_types'] = $this->responseTypes;
    }
    if ($this->tokenEndpointAuthMethod !== NULL) {
      $data['token_endpoint_auth_method'] = $this->tokenEndpointAuthMethod;
    }
    if ($this->scope !== NULL) {
      $data['scope'] = $this->scope;
    }
    if ($this->clientUri !== NULL) {
      $data['client_uri'] = $this->clientUri;
    }
    if ($this->logoUri !== NULL) {
      $data['logo_uri'] = $this->logoUri;
    }
    if (!empty($this->contacts)) {
      $data['contacts'] = $this->contacts;
    }
    if ($this->tosUri !== NULL) {
      $data['tos_uri'] = $this->tosUri;
    }
    if ($this->policyUri !== NULL) {
      $data['policy_uri'] = $this->policyUri;
    }
    if ($this->jwksUri !== NULL) {
      $data['jwks_uri'] = $this->jwksUri;
    }
    if ($this->softwareId !== NULL) {
      $data['software_id'] = $this->softwareId;
    }
    if ($this->softwareVersion !== NULL) {
      $data['software_version'] = $this->softwareVersion;
    }
    if ($this->applicationType !== NULL) {
      $data['application_type'] = $this->applicationType;
    }
    if ($this->clientId !== NULL) {
      $data['client_id'] = $this->clientId;
    }
    if ($this->clientSecret !== NULL) {
      $data['client_secret'] = $this->clientSecret;
    }
    if ($this->registrationAccessToken !== NULL) {
      $data['registration_access_token'] = $this->registrationAccessToken;
    }
    if ($this->registrationClientUri !== NULL) {
      $data['registration_client_uri'] = $this->registrationClientUri;
    }
    if ($this->clientIdIssuedAt !== NULL) {
      $data['client_id_issued_at'] = $this->clientIdIssuedAt;
    }
    if ($this->clientSecretExpiresAt !== NULL) {
      $data['client_secret_expires_at'] = $this->clientSecretExpiresAt;
    }

    return $data;
  }

  /**
   * Getters for all properties.
   */
  public function getClientName(): ?string {
    return $this->clientName;
  }

  /**
   * Gets the redirect URIs.
   *
   * @return array
   *   The redirect URIs.
   */
  public function getRedirectUris(): array {
    return $this->redirectUris;
  }

  /**
   * Gets the grant types.
   *
   * @return array
   *   The grant types.
   */
  public function getGrantTypes(): array {
    return $this->grantTypes;
  }

  /**
   * Gets the response types.
   *
   * @return array
   *   The response types.
   */
  public function getResponseTypes(): array {
    return $this->responseTypes;
  }

  /**
   * Gets the token endpoint authentication method.
   *
   * @return string|null
   *   The authentication method or NULL.
   */
  public function getTokenEndpointAuthMethod(): ?string {
    return $this->tokenEndpointAuthMethod;
  }

  /**
   * Gets the scope.
   *
   * @return string|null
   *   The scope or NULL.
   */
  public function getScope(): ?string {
    return $this->scope;
  }

  /**
   * Gets the client URI.
   *
   * @return string|null
   *   The client URI or NULL.
   */
  public function getClientUri(): ?string {
    return $this->clientUri;
  }

  /**
   * Gets the logo URI.
   *
   * @return string|null
   *   The logo URI or NULL.
   */
  public function getLogoUri(): ?string {
    return $this->logoUri;
  }

  /**
   * Gets the contacts.
   *
   * @return array
   *   The contacts.
   */
  public function getContacts(): array {
    return $this->contacts;
  }

  /**
   * Gets the terms of service URI.
   *
   * @return string|null
   *   The terms of service URI or NULL.
   */
  public function getTosUri(): ?string {
    return $this->tosUri;
  }

  /**
   * Gets the policy URI.
   *
   * @return string|null
   *   The policy URI or NULL.
   */
  public function getPolicyUri(): ?string {
    return $this->policyUri;
  }

  /**
   * Gets the JWKS URI.
   *
   * @return string|null
   *   The JWKS URI or NULL.
   */
  public function getJwksUri(): ?string {
    return $this->jwksUri;
  }

  /**
   * Gets the software ID.
   *
   * @return string|null
   *   The software ID or NULL.
   */
  public function getSoftwareId(): ?string {
    return $this->softwareId;
  }

  /**
   * Gets the software version.
   *
   * @return string|null
   *   The software version or NULL.
   */
  public function getSoftwareVersion(): ?string {
    return $this->softwareVersion;
  }

  /**
   * Gets the application type.
   *
   * @return string|null
   *   The application type or NULL.
   */
  public function getApplicationType(): ?string {
    return $this->applicationType;
  }

  /**
   * Gets the client ID.
   *
   * @return string|null
   *   The client ID or NULL.
   */
  public function getClientId(): ?string {
    return $this->clientId;
  }

  /**
   * Gets the client secret.
   *
   * @return string|null
   *   The client secret or NULL.
   */
  public function getClientSecret(): ?string {
    return $this->clientSecret;
  }

  /**
   * Gets the registration access token.
   *
   * @return string|null
   *   The registration access token or NULL.
   */
  public function getRegistrationAccessToken(): ?string {
    return $this->registrationAccessToken;
  }

  /**
   * Gets the registration client URI.
   *
   * @return string|null
   *   The registration client URI or NULL.
   */
  public function getRegistrationClientUri(): ?string {
    return $this->registrationClientUri;
  }

  /**
   * Gets the client ID issued at timestamp.
   *
   * @return int|null
   *   The timestamp when client ID was issued or NULL.
   */
  public function getClientIdIssuedAt(): ?int {
    return $this->clientIdIssuedAt;
  }

  /**
   * Gets the client secret expiration timestamp.
   *
   * @return int|null
   *   The timestamp when client secret expires or NULL.
   */
  public function getClientSecretExpiresAt(): ?int {
    return $this->clientSecretExpiresAt;
  }

  /**
   * Determines if the client is confidential.
   *
   * @return bool
   *   TRUE if confidential, FALSE otherwise.
   */
  public function isConfidential(): bool {
    return $this->tokenEndpointAuthMethod !== 'none';
  }

  /**
   * Creates a new instance with additional response fields.
   *
   * @param string $clientId
   *   The client identifier.
   * @param string|null $clientSecret
   *   The client secret.
   * @param string $registrationAccessToken
   *   The registration access token.
   * @param string $registrationClientUri
   *   The registration client URI.
   * @param int $clientIdIssuedAt
   *   When the client ID was issued.
   * @param int $clientSecretExpiresAt
   *   When the client secret expires.
   *
   * @return self
   *   A new instance with response fields.
   */
  public function withResponseFields(
    string $clientId,
    ?string $clientSecret,
    string $registrationAccessToken,
    string $registrationClientUri,
    int $clientIdIssuedAt,
    int $clientSecretExpiresAt = 0,
  ): self {
    return new self(
      $this->clientName,
      $this->redirectUris,
      $this->grantTypes,
      $this->responseTypes,
      $this->tokenEndpointAuthMethod,
      $this->scope,
      $this->clientUri,
      $this->logoUri,
      $this->contacts,
      $this->tosUri,
      $this->policyUri,
      $this->jwksUri,
      $this->softwareId,
      $this->softwareVersion,
      $this->applicationType,
      $clientId,
      $clientSecret,
      $registrationAccessToken,
      $registrationClientUri,
      $clientIdIssuedAt,
      $clientSecretExpiresAt,
    );
  }

}
