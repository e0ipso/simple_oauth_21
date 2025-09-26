---
id: 6
group: 'endpoints'
dependencies: [5]
status: 'pending'
created: '2025-09-26'
skills: ['drupal-backend', 'api-endpoints']
---

# Implement Device Authorization Controller

## Objective

Create the DeviceAuthorizationController that handles POST requests to /oauth/device_authorization, generates device and user codes, and returns RFC 8628-compliant JSON responses.

## Skills Required

- **drupal-backend**: Controller patterns, dependency injection
- **api-endpoints**: JSON responses, HTTP handling, OAuth endpoints

## Acceptance Criteria

- [ ] Controller handles POST to /oauth/device_authorization
- [ ] Validates client_id parameter
- [ ] Generates device and user codes via grant
- [ ] Returns RFC-compliant JSON response
- [ ] Proper error handling and responses
- [ ] Integration with league/oauth2-server grant

## Technical Requirements

- Extend ControllerBase
- Use PSR-7 request/response handling
- Integrate with DeviceCodeGrant
- Return proper JSON format per RFC 8628
- Handle OAuth exceptions appropriately

## Input Dependencies

- DeviceCode grant plugin from task 5
- Routing configuration from task 1

## Output Artifacts

- src/Controller/DeviceAuthorizationController.php
- Device authorization endpoint implementation
- RFC-compliant JSON responses

## Implementation Notes

<details>
<summary>Detailed implementation guidance</summary>

**Controller structure (study simple_oauth controllers):**

```php
class DeviceAuthorizationController extends ControllerBase {

  public function __construct(
    private HttpMessageFactoryInterface $httpMessageFactory,
    private PluginManagerInterface $grantManager,
    private ClientRepositoryInterface $clientRepository,
    private LoggerInterface $logger
  ) {}

  public function authorize(Request $request): JsonResponse {
    try {
      // Convert to PSR-7 request
      $serverRequest = $this->httpMessageFactory->createRequest($request);

      // Get device code grant
      $grant = $this->grantManager->createInstance('device_code');

      // Check if grant can respond to device authorization request
      if (!$grant->canRespondToDeviceAuthorizationRequest($serverRequest)) {
        throw OAuthServerException::unsupportedGrantType();
      }

      // Generate device authorization response
      $response = $grant->respondToDeviceAuthorizationRequest($serverRequest);

      // Convert to Drupal JsonResponse
      return new JsonResponse(json_decode($response->getBody()->getContents(), TRUE));

    } catch (OAuthServerException $exception) {
      $this->logger->error('Device authorization error: @message', [
        '@message' => $exception->getMessage(),
      ]);

      return new JsonResponse([
        'error' => $exception->getErrorType(),
        'error_description' => $exception->getMessage(),
      ], $exception->getHttpStatusCode());
    }
  }
}
```

**Required JSON response format (RFC 8628):**

```json
{
  "device_code": "4d03f7bc-f7a5-4795-819a-5748c4801d35",
  "user_code": "HJKL-QWER",
  "verification_uri": "https://example.com/oauth/device",
  "verification_uri_complete": "https://example.com/oauth/device?user_code=HJKL-QWER",
  "expires_in": 1800,
  "interval": 5
}
```

**Error handling:**

- Invalid client: 400 with invalid_client
- Missing client_id: 400 with invalid_request
- Internal errors: 500 with server_error
- Log all authorization attempts
</details>
