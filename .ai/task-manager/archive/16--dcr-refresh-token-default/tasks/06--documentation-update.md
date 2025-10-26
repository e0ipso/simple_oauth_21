---
id: 6
group: 'documentation'
dependencies: [1, 2, 3, 4]
status: 'completed'
created: 2025-10-22
skills:
  - documentation
---

# Update README with Configuration Documentation

## Objective

Update the `simple_oauth_client_registration` module README to document the new `auto_enable_refresh_token` configuration option, its default value, RFC 7591 compliance rationale, and clarify that it only affects the DCR endpoint.

## Skills Required

- `documentation`: Technical writing, markdown formatting, API documentation

## Acceptance Criteria

- [ ] README.md updated with new "Configuration" section (or subsection if section exists)
- [ ] `auto_enable_refresh_token` setting documented with clear description
- [ ] Default value (`true`) and rationale explained
- [ ] RFC 7591 Section 3.2.1 compliance noted
- [ ] Scope clarified: DCR endpoint only, not admin UI
- [ ] Example registration requests showing behavior with/without explicit grant_types
- [ ] Markdown formatting is clean and consistent with existing README style
- [ ] No trailing spaces, proper newlines

## Technical Requirements

**Module**: `simple_oauth_client_registration`

**File to Modify**: `modules/simple_oauth_client_registration/README.md`

**Content to Add**:

- Configuration section explaining the setting
- RFC 7591 compliance note
- Scope clarification (DCR only)
- Example registration requests

**Suggested Location**: Add after "Configuration" or "Basic Setup" section, before "API Reference"

## Input Dependencies

- Tasks 1-4: Implementation must be complete to accurately document behavior
- Existing README.md content and structure

## Output Artifacts

- Updated README.md with comprehensive configuration documentation
- Helps users understand and configure the new feature
- Provides examples for common use cases

## Implementation Notes

<details>
<summary>Detailed Implementation Steps</summary>

### Step 1: Read Existing README

```bash
cd /var/www/html/web/modules/contrib/simple_oauth_21/modules/simple_oauth_client_registration
```

Read `README.md` to understand:

- Existing section structure
- Markdown style and formatting conventions
- Where to insert new configuration documentation

### Step 2: Add Configuration Section

Add this content to the README (adjust placement based on existing structure):

````markdown
### Default Grant Types Configuration

The Client Registration module can automatically enable the `refresh_token` grant for clients that register without explicitly specifying grant types. This aligns with OAuth 2.1 best practices for native applications and public clients using PKCE.

#### Configuration Setting

Navigate to: **Administration → Configuration → People → Simple OAuth → Client Registration**
(`/admin/config/people/simple_oauth/client-registration`)

**Auto-enable refresh_token grant**: When enabled (default), clients registering via `POST /oauth/register` without specifying `grant_types` will receive both `authorization_code` and `refresh_token` grants.

**Default**: Enabled (`true`)

#### RFC 7591 Compliance

This feature is fully compliant with RFC 7591 Dynamic Client Registration:

- **Section 3.2.1**: "The authorization server MAY reject or replace any of the client's requested metadata values submitted during the registration and substitute them with suitable values."
- **Section 3.1**: "The authorization server MAY provision default values for any items omitted in the client metadata."

#### Scope

**Important**: This setting only affects the Dynamic Client Registration endpoint (`POST /oauth/register`). Manual client creation via the Drupal admin UI is not affected by this configuration.

Clients that explicitly specify `grant_types` in their registration request will always have their choices respected, regardless of this setting.

#### Examples

**Example 1: Client omits grant_types (setting enabled)**

Request:

```json
POST /oauth/register
{
  "client_name": "My Native App",
  "redirect_uris": ["myapp://callback"]
}
```
````

Response:

```json
{
  "client_id": "...",
  "client_secret": "...",
  "grant_types": ["authorization_code", "refresh_token"]
}
```

**Example 2: Client omits grant_types (setting disabled)**

Request: Same as above

Response:

```json
{
  "client_id": "...",
  "client_secret": "...",
  "grant_types": ["authorization_code"]
}
```

**Example 3: Client explicitly specifies grant_types**

Request:

```json
POST /oauth/register
{
  "client_name": "My Service",
  "redirect_uris": ["https://example.com/callback"],
  "grant_types": ["authorization_code", "client_credentials"]
}
```

Response: (setting has no effect - explicit request is honored)

```json
{
  "client_id": "...",
  "client_secret": "...",
  "grant_types": ["authorization_code", "client_credentials"]
}
```

```

### Step 3: Review Existing Sections

Ensure the new content:
- Fits naturally with existing documentation flow
- Uses consistent heading levels (### for subsections)
- Follows existing code block formatting
- Maintains consistent tone and style

### Step 4: Verify Markdown Rendering

After editing, verify markdown renders correctly:
- Code blocks are properly fenced with language identifiers
- Lists are formatted consistently
- Links work if any are added
- No broken formatting

### Key Documentation Points
- **Clear Structure**: Use headings to organize content logically
- **Examples**: Provide concrete request/response examples
- **Scope Clarity**: Emphasize DCR-only scope to avoid confusion
- **RFC Citations**: Reference specific RFC sections for authority
- **User Actions**: Include navigation path to admin UI
- **Default Behavior**: Clearly state the default value and rationale

### Coding Standards
- No trailing spaces
- Newline at end of file
- Consistent markdown formatting
- Proper code block language identifiers (json, bash, etc.)

</details>
```
