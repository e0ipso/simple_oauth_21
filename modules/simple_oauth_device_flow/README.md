# Simple OAuth Device Flow

This module implements RFC 8628 OAuth 2.0 Device Authorization Grant for Drupal, enabling OAuth authentication for devices with limited input capabilities such as smart TVs, streaming devices, IoT devices, and command-line applications.

## Overview

The Device Authorization Grant (also known as Device Flow) is designed for OAuth clients on devices that either lack a browser or have limited input capabilities. Instead of redirecting users to an authorization server like traditional OAuth flows, the device flow allows users to authorize the device using a separate user-agent (like a smartphone or computer).

### RFC 8628 Compliance

This module provides full compliance with [RFC 8628 OAuth 2.0 Device Authorization Grant](https://tools.ietf.org/html/rfc8628), including:

- Device authorization endpoint (`/oauth/device_authorization`)
- User verification interface (`/oauth/device`)
- Device code and user code generation
- Polling mechanism for token exchange
- Proper error handling and security measures

## Requirements

- Drupal 10.2+ or Drupal 11+
- Simple OAuth 2.1 module (`simple_oauth_21`)
- Simple OAuth module (`simple_oauth`)
- Consumers module (`consumers`)

## Installation

1. Install the required dependencies:
   ```bash
   composer require drupal/simple_oauth drupal/consumers
   ```

2. Enable the module:
   ```bash
   drush pm:enable simple_oauth_device_flow
   ```

3. Clear cache:
   ```bash
   drush cache:rebuild
   ```

## Configuration

### Administrative Interface

Navigate to **Administration » Configuration » People » Simple OAuth » OAuth 2.1 » Device Flow Settings** (`/admin/config/people/simple_oauth/oauth-21/device-flow`) to configure:

### Configuration Options

| Setting | Description | Default | Recommended |
|---------|-------------|---------|-------------|
| **Device Code Lifetime** | How long device codes remain valid (seconds) | 1800 (30 min) | 300-1800 |
| **Polling Interval** | Minimum seconds between token polling requests | 5 | 5-15 |
| **User Code Length** | Length of human-readable user codes | 8 | 6-12 |
| **User Code Charset** | Characters allowed in user codes | `BCDFGHJKLMNPQRSTVWXZ` | Exclude ambiguous chars |
| **Verification URI** | Path for user verification | `/oauth/device` | Keep default |
| **Verification URI Complete** | Include device code in verification URI | `true` | `true` for UX |
| **Cleanup Retention Days** | Days to keep completed device codes | 7 | 1-30 |
| **Max Cleanup Batch Size** | Maximum codes to clean up per batch | 1000 | 500-2000 |
| **Enable Statistics Logging** | Log device flow usage statistics | `true` | `true` for monitoring |

### OAuth Client Configuration

1. Create an OAuth client at **Administration » Configuration » Web Services » Consumers**
2. Configure the client with:
   - **Grant Types**: Include "Device Code"
   - **Scopes**: Define appropriate scopes for your application
   - **Confidential**: Set to `true` for most device applications

## API Endpoints

### Device Authorization Endpoint

**Endpoint:** `POST /oauth/device_authorization`

Initiates the device authorization flow by generating device and user codes.

#### Request Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `client_id` | Yes | OAuth client identifier |
| `scope` | No | Space-delimited list of requested scopes |

#### Example Request

```bash
curl -X POST https://your-site.com/oauth/device_authorization \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=your_client_id&scope=read write"
```

#### Example Response

```json
{
  "device_code": "GmRhmhcxhwAzkoEqiMEg_DnyEysNkuNhszIySk9eS",
  "user_code": "WDJB-MJHT",
  "verification_uri": "https://your-site.com/oauth/device",
  "verification_uri_complete": "https://your-site.com/oauth/device?user_code=WDJB-MJHT",
  "expires_in": 1800,
  "interval": 5
}
```

### User Verification Interface

**Endpoint:** `GET /oauth/device`

Displays the user verification form where users enter their device code.

#### Query Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `user_code` | No | Pre-filled user code (when using verification_uri_complete) |

### Token Exchange

Use the standard OAuth token endpoint with the device code grant:

**Endpoint:** `POST /oauth/token`

#### Request Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `grant_type` | Yes | Must be `urn:ietf:params:oauth:grant-type:device_code` |
| `device_code` | Yes | Device code from authorization response |
| `client_id` | Yes | OAuth client identifier |

#### Example Request

```bash
curl -X POST https://your-site.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=urn:ietf:params:oauth:grant-type:device_code&device_code=GmRhmhcxhwAzkoEqiMEg_DnyEysNkuNhszIySk9eS&client_id=your_client_id"
```

## Device Flow Workflow

### 1. Device Requests Authorization

The device makes a request to the device authorization endpoint:

```bash
POST /oauth/device_authorization
Content-Type: application/x-www-form-urlencoded

client_id=your_client_id&scope=read+write
```

### 2. Server Responds with Codes

The server returns device and user codes:

```json
{
  "device_code": "GmRhmhcxhwAzkoEqiMEg_DnyEysNkuNhszIySk9eS",
  "user_code": "WDJB-MJHT",
  "verification_uri": "https://your-site.com/oauth/device",
  "expires_in": 1800,
  "interval": 5
}
```

### 3. Device Displays Instructions

The device displays instructions to the user:

```
Please visit: https://your-site.com/oauth/device
And enter code: WDJB-MJHT
```

### 4. User Authorizes Device

1. User visits the verification URI in a web browser
2. User enters the user code (WDJB-MJHT)
3. User authenticates with Drupal (if not already logged in)
4. User reviews and approves the authorization request

### 5. Device Polls for Token

While waiting for user authorization, the device polls the token endpoint:

```bash
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=urn:ietf:params:oauth:grant-type:device_code&device_code=GmRhmhcxhwAzkoEqiMEg_DnyEysNkuNhszIySk9eS&client_id=your_client_id
```

### 6. Token Exchange Success

Once authorized, the server returns an access token:

```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "def50200..."
}
```

## Error Handling

### Device Authorization Errors

| Error Code | Description | HTTP Status |
|------------|-------------|-------------|
| `invalid_request` | Missing or invalid request parameters | 400 |
| `invalid_client` | Invalid client_id | 400 |
| `invalid_scope` | Invalid or unknown scope | 400 |
| `server_error` | Internal server error | 500 |

### Token Exchange Errors

| Error Code | Description | Action |
|------------|-------------|--------|
| `authorization_pending` | User hasn't completed authorization yet | Continue polling |
| `slow_down` | Polling too frequently | Increase polling interval |
| `expired_token` | Device code has expired | Start new flow |
| `access_denied` | User denied the request | Handle denial |

### Example Error Response

```json
{
  "error": "authorization_pending",
  "error_description": "The authorization request is still pending as the end user hasn't yet completed the user interaction steps"
}
```

## Security Considerations

### Rate Limiting

- Implement rate limiting on device authorization requests
- Monitor for excessive polling attempts
- Configure appropriate polling intervals (5-15 seconds recommended)

### Code Security

- Device codes are cryptographically secure (128+ bits entropy)
- User codes use a character set that avoids ambiguous characters
- Codes automatically expire after the configured lifetime

### User Experience

- Clear instructions displayed to users
- Responsive verification interface
- Proper error messaging for invalid codes

## Integration Examples

### Python Client

```python
import requests
import time

# Step 1: Request device authorization
auth_response = requests.post('https://your-site.com/oauth/device_authorization', {
    'client_id': 'your_client_id',
    'scope': 'read write'
})
auth_data = auth_response.json()

print(f"Visit: {auth_data['verification_uri']}")
print(f"Enter code: {auth_data['user_code']}")

# Step 2: Poll for token
device_code = auth_data['device_code']
interval = auth_data['interval']

while True:
    token_response = requests.post('https://your-site.com/oauth/token', {
        'grant_type': 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code': device_code,
        'client_id': 'your_client_id'
    })

    token_data = token_response.json()

    if 'access_token' in token_data:
        print(f"Access token: {token_data['access_token']}")
        break
    elif token_data.get('error') == 'authorization_pending':
        time.sleep(interval)
        continue
    else:
        print(f"Error: {token_data.get('error')}")
        break
```

### JavaScript/Node.js Client

```javascript
const axios = require('axios');

async function deviceFlow() {
  // Step 1: Request device authorization
  const authResponse = await axios.post('https://your-site.com/oauth/device_authorization', {
    client_id: 'your_client_id',
    scope: 'read write'
  });

  const { device_code, user_code, verification_uri, interval } = authResponse.data;

  console.log(`Visit: ${verification_uri}`);
  console.log(`Enter code: ${user_code}`);

  // Step 2: Poll for token
  while (true) {
    try {
      const tokenResponse = await axios.post('https://your-site.com/oauth/token', {
        grant_type: 'urn:ietf:params:oauth:grant-type:device_code',
        device_code: device_code,
        client_id: 'your_client_id'
      });

      console.log('Access token:', tokenResponse.data.access_token);
      break;
    } catch (error) {
      const errorData = error.response?.data;
      if (errorData?.error === 'authorization_pending') {
        await new Promise(resolve => setTimeout(resolve, interval * 1000));
        continue;
      }
      console.error('Error:', errorData?.error);
      break;
    }
  }
}

deviceFlow();
```

## Maintenance and Monitoring

### Automated Cleanup

The module includes automated cleanup of expired device codes:

- Configurable retention period (default: 7 days)
- Batch processing for performance
- Logging of cleanup operations

### Monitoring

Enable statistics logging to monitor:

- Device authorization requests
- User verification attempts
- Token exchange success/failure rates
- Cleanup operations

### Performance Optimization

- Database indexes on device_code and user_code fields
- Efficient cleanup queries with batch processing
- Configurable batch sizes for large datasets

## Troubleshooting

### Common Issues

**Issue: "Invalid device code" error**
- Verify device code hasn't expired (check `expires_in`)
- Ensure device code is being passed correctly
- Check for typos in device code

**Issue: "Authorization pending" continues indefinitely**
- Verify user completed verification process
- Check if user code was entered correctly
- Ensure user is properly authenticated

**Issue: Device authorization endpoint returns 404**
- Verify module is enabled
- Clear Drupal cache
- Check routing configuration

**Issue: User verification page not accessible**
- Check permissions for anonymous users
- Verify route is not being overridden
- Clear Drupal cache

### Debug Steps

1. **Check module status:**
   ```bash
   drush pm:list --type=module --filter=device_flow
   ```

2. **Clear cache:**
   ```bash
   drush cache:rebuild
   ```

3. **Check logs:**
   ```bash
   drush watchdog:show --filter=simple_oauth_device_flow
   ```

4. **Verify OAuth client configuration:**
   - Navigate to `/admin/config/services/consumer`
   - Verify client has "Device Code" grant type enabled

### Performance Issues

If experiencing performance issues with large numbers of device codes:

1. Increase cleanup batch size in configuration
2. Consider reducing retention period
3. Monitor database performance during cleanup operations
4. Consider implementing additional database indexes if needed

## Development and Extension

### Services Available

The module provides several services for custom development:

- `simple_oauth_device_flow.device_code_service` - Device code lifecycle management
- `simple_oauth_device_flow.user_code_generator` - User code generation
- `simple_oauth_device_flow.settings` - Configuration management

### Custom Integrations

To integrate with custom applications:

1. Use the provided API endpoints
2. Implement proper error handling
3. Follow RFC 8628 specifications
4. Consider implementing device-specific user experience

### Testing

The module includes comprehensive tests:

- Functional tests for user workflows
- Unit tests for services and entities
- Kernel tests for integration scenarios

Run tests with:
```bash
vendor/bin/phpunit --group simple_oauth_device_flow
```

## Support and Contributing

For issues, feature requests, or contributions:

1. Check existing issues in the project queue
2. Follow Drupal coding standards
3. Include tests for new functionality
4. Update documentation as needed

## License

This module is licensed under the GPL v2+ license, consistent with Drupal core.