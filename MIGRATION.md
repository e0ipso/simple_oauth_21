# Migration Guide: OAuth RFC Compliance

This guide helps you migrate existing Simple OAuth installations to take advantage of the new OAuth RFC compliance features introduced in Simple OAuth 2.1.

## Overview

The OAuth RFC compliance implementation adds several new capabilities:

- **RFC 7591**: Dynamic Client Registration
- **RFC 9728**: Protected Resource Metadata
- **RFC 8414**: Enhanced Authorization Server Metadata
- **Enhanced Security**: Additional validation and monitoring

## Pre-Migration Assessment

### 1. Check Current Installation

Before starting the migration, assess your current OAuth setup:

```bash
# Check installed Simple OAuth modules
drush pm:list --type=module --status=enabled | grep simple_oauth

# Check OAuth consumer configuration
drush config:get simple_oauth.settings

# Review existing OAuth consumers
drush sql:query "SELECT uuid, label, secret FROM consumer"
```

### 2. Backup Configuration

Create backups before making changes:

```bash
# Export current configuration
drush config:export

# Backup database
drush sql:dump --result-file=backup-pre-migration-$(date +%Y%m%d).sql

# Backup custom client configurations (if any)
cp -r web/sites/default/files/oauth_backup_$(date +%Y%m%d)/
```

### 3. Review Client Applications

Document your current OAuth clients:

- List all registered applications
- Note redirect URIs and grant types
- Identify native applications vs web applications
- Document any custom client configurations

## Migration Steps

### Step 1: Install New Submodules

Install the OAuth RFC compliance submodules:

```bash
# Install via Composer (if not already included)
composer require e0ipso/simple_oauth_21

# Enable the new compliance submodules
drush pm:enable simple_oauth_client_registration

# Clear caches
drush cache:rebuild
```

### Step 2: Update Database Schema

The new submodules add fields to the consumer entity:

```bash
# Run database updates
drush updatedb

# Check for any pending updates
drush updatedb --dry-run
```

### Step 3: Configure New Features

#### 3.1 Enable Server Metadata (if not already enabled)

```bash
# Enable server metadata module
drush pm:enable simple_oauth_server_metadata

# Test the metadata endpoint
curl -X GET https://your-site.com/.well-known/oauth-authorization-server
```

#### 3.2 Configure Client Registration

1. **Set Permissions**: Navigate to `/admin/people/permissions` and configure:
   - "Register OAuth clients" (for public registration)
   - "Administer OAuth clients" (for full client management)

2. **Test Registration Endpoint**:
   ```bash
   curl -X POST https://your-site.com/oauth/register \
     -H "Content-Type: application/json" \
     -d '{
       "client_name": "Test Migration Client",
       "redirect_uris": ["https://example.com/callback"]
     }'
   ```

#### 3.3 Configure Protected Resource Metadata

The protected resource metadata endpoint is automatically available at:
`/.well-known/oauth-protected-resource`

Test it:

```bash
curl -X GET https://your-site.com/.well-known/oauth-protected-resource
```

### Step 4: Migrate Existing Clients

#### 4.1 Update Client Metadata

Existing clients can be enhanced with new metadata fields:

1. Navigate to `/admin/config/people/simple_oauth/oauth2_client`
2. Edit existing clients to add:
   - Client Name (display name)
   - Client URI (homepage)
   - Logo URI (for consent screens)
   - Contact information
   - Terms of Service URI
   - Privacy Policy URI

#### 4.2 Native App Client Migration

If you have mobile or desktop applications:

1. **Enable Native Apps Module** (if not already enabled):

   ```bash
   drush pm:enable simple_oauth_native_apps
   ```

2. **Update Native Client Configuration**:
   - Review redirect URIs for custom schemes
   - Enable enhanced PKCE for native clients
   - Configure WebView detection policies

#### 4.3 Programmatic Client Migration

For clients with existing metadata, use the new fields:

```php
// Example: Update existing client with new metadata
$client = \Drupal::entityTypeManager()
  ->getStorage('consumer')
  ->load('your-client-uuid');

$client->set('client_name', 'My Application');
$client->set('client_uri', 'https://myapp.example.com');
$client->set('logo_uri', 'https://myapp.example.com/logo.png');
$client->set('contacts', ['admin@myapp.example.com']);
$client->set('tos_uri', 'https://myapp.example.com/terms');
$client->set('policy_uri', 'https://myapp.example.com/privacy');
$client->save();
```

## Post-Migration Validation

### 1. Test Core Functionality

Verify existing OAuth flows still work:

```bash
# Test authorization code flow
curl -X POST https://your-site.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=authorization_code&code=YOUR_CODE&client_id=YOUR_CLIENT&client_secret=YOUR_SECRET"

# Test client credentials flow
curl -X POST https://your-site.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials&client_id=YOUR_CLIENT&client_secret=YOUR_SECRET"
```

### 2. Validate New Endpoints

Test all new RFC compliance endpoints:

```bash
# Authorization server metadata
curl -X GET https://your-site.com/.well-known/oauth-authorization-server

# Protected resource metadata
curl -X GET https://your-site.com/.well-known/oauth-protected-resource

# Client registration (if enabled)
curl -X POST https://your-site.com/oauth/register \
  -H "Content-Type: application/json" \
  -d '{"client_name": "Test Client", "redirect_uris": ["https://example.com/callback"]}'
```

### 3. Check Compliance Dashboard

1. Navigate to `/admin/config/people/simple_oauth/oauth-21`
2. Review compliance status
3. Address any critical issues identified
4. Verify "Fully Compliant" or "Mostly Compliant" status

## Client Application Updates

### For Application Developers

If you maintain client applications, consider these updates:

#### 1. Discover Server Capabilities

Use server metadata for automatic configuration:

```javascript
// Fetch server metadata
const metadata = await fetch(
  'https://your-site.com/.well-known/oauth-authorization-server',
).then(response => response.json());

// Configure client with discovered endpoints
const oauthConfig = {
  authorizationEndpoint: metadata.authorization_endpoint,
  tokenEndpoint: metadata.token_endpoint,
  registrationEndpoint: metadata.registration_endpoint,
};
```

#### 2. Use Dynamic Registration

For new clients, use dynamic registration:

```javascript
// Register client dynamically
const client = await fetch('https://your-site.com/oauth/register', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    client_name: 'My Application',
    redirect_uris: ['https://myapp.example.com/callback'],
    grant_types: ['authorization_code'],
    scope: 'read write',
  }),
}).then(response => response.json());

// Store client credentials
localStorage.setItem('oauth_client_id', client.client_id);
localStorage.setItem('oauth_client_secret', client.client_secret);
```

#### 3. Update Client Metadata

Add rich metadata to improve user experience:

```json
{
  "client_name": "My Amazing App",
  "client_uri": "https://myapp.example.com",
  "logo_uri": "https://myapp.example.com/logo.png",
  "tos_uri": "https://myapp.example.com/terms",
  "policy_uri": "https://myapp.example.com/privacy",
  "contacts": ["support@myapp.example.com"]
}
```

## Troubleshooting Migration Issues

### Common Problems and Solutions

#### Problem: Client registration returns 403 Forbidden

**Solution**: Check permissions and module configuration

```bash
# Verify module is enabled
drush pm:list | grep simple_oauth_client_registration

# Check permissions at /admin/people/permissions
# Ensure "Register OAuth clients" permission is granted
```

#### Problem: Metadata endpoints return 404

**Solution**: Verify server metadata module is enabled

```bash
drush pm:enable simple_oauth_server_metadata
drush cache:rebuild
```

#### Problem: Existing clients not working

**Solution**: Check database updates and field additions

```bash
# Run pending updates
drush updatedb

# Clear all caches
drush cache:rebuild

# Check entity field definitions
drush sql:query "DESCRIBE consumer"
```

#### Problem: Native app detection not working

**Solution**: Verify native apps module and redirect URI patterns

```bash
# Enable native apps module
drush pm:enable simple_oauth_native_apps

# Check redirect URI patterns in client configuration
# Custom schemes like "myapp://" should be detected automatically
```

### Debug Mode

Enable detailed logging for troubleshooting:

```php
// Add to settings.php for temporary debugging
$config['system.logging']['error_level'] = 'verbose';

// Enable module-specific logging
\Drupal::logger('simple_oauth_client_registration')->debug('Debug info');
```

## Performance Considerations

### Caching

The new metadata endpoints are cached for performance:

```bash
# Clear metadata caches if needed
drush cache:clear metadata

# Or clear all caches
drush cache:rebuild
```

### Database Optimization

New fields are added to the consumer entity:

```sql
-- Check new field usage
SELECT client_name, client_uri, logo_uri
FROM consumer
WHERE client_name IS NOT NULL;

-- Index frequently queried fields if needed
CREATE INDEX idx_consumer_client_name ON consumer (client_name);
```

## Security Considerations

### Access Control

Review and configure access permissions:

1. **Client Registration**: Limit who can register new clients
2. **Client Management**: Restrict client modification permissions
3. **Metadata Access**: Metadata endpoints are public by design

### Validation

Enhanced validation is now active:

- Redirect URI pattern validation
- Client metadata validation
- Enhanced security checks for native apps

### Monitoring

Consider monitoring the new endpoints:

```bash
# Monitor registration endpoint usage
tail -f /var/log/drupal/oauth_registration.log

# Watch for unusual client registration patterns
grep "client_registration" /var/log/drupal/*.log
```

## Rollback Plan

If issues arise, you can rollback the migration:

### 1. Disable New Modules

```bash
# Disable new submodules
drush pm:uninstall simple_oauth_client_registration

# Clear caches
drush cache:rebuild
```

### 2. Restore Configuration

```bash
# Restore previous configuration
drush config:import --source=backup-directory

# Restore database if needed
drush sql:cli < backup-pre-migration-YYYYMMDD.sql
```

### 3. Verify Functionality

Test that existing OAuth flows work after rollback.

## Support and Resources

### Documentation

- [API Documentation](./API.md)
- [Main README](./README.md)
- [Module Help Pages](/admin/help/simple_oauth_client_registration)

### Community Support

- [Drupal Simple OAuth Issue Queue](https://www.drupal.org/project/issues/simple_oauth)
- [OAuth RFC Specifications](https://oauth.net/specs/)

### Professional Support

For complex migrations or enterprise deployments, consider professional Drupal OAuth consulting services.

## Next Steps

After successful migration:

1. **Monitor Performance**: Watch for any performance impacts
2. **Update Documentation**: Update internal documentation with new capabilities
3. **Train Users**: Educate developers on new endpoints and features
4. **Plan Enhancement**: Consider additional OAuth security features
5. **Regular Reviews**: Periodically review compliance status and client configurations

The migration to OAuth RFC compliance provides significant benefits in security, interoperability, and developer experience. Take time to fully explore the new capabilities and consider how they can improve your OAuth implementation.
