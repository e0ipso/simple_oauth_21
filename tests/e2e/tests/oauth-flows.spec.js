const { test, expect } = require('@playwright/test');
const { randomBytes, createHash, createPublicKey, verify } = require('crypto');
const { createUniqueAdminUser } = require('../utils/test-setup');
const { execDrushInTestSite } = require('../utils/drush-helper');

const deviceGrant = 'urn:ietf:params:oauth:grant-type:device_code';
let user;
let fixture;

// These flows temporarily change shared policy settings on the test site.
test.describe.configure({ timeout: 120000 });

async function php(code) {
  const encoded = Buffer.from(code).toString('base64');
  return execDrushInTestSite(`php:eval 'eval(base64_decode("${encoded}"));'`);
}

async function login(page) {
  await page.goto('/user/login');
  await page.getByLabel('Username', { exact: true }).fill(user.username);
  await page.getByLabel('Password', { exact: true }).fill(user.password);
  await page.getByRole('button', { name: 'Log in', exact: true }).click();
  await expect(
    page.getByRole('button', { name: 'Log in', exact: true }),
  ).toHaveCount(0);
}

async function jsonResponse(response, status) {
  expect(response.status(), await response.text()).toBe(status);
  expect(response.headers()['content-type']).toContain('application/json');
  return response.json();
}

test.beforeAll(async ({ baseURL }) => {
  await execDrushInTestSite(
    'pm:enable simple_oauth_native_apps simple_oauth_pkce simple_oauth_device_flow simple_oauth_client_registration simple_oauth_server_metadata -y',
    { timeout: 120000 },
  );
  user = await createUniqueAdminUser('oauth_flows');
  const options = Buffer.from(
    JSON.stringify({ username: user.username, baseURL, deviceGrant }),
  ).toString('base64');
  fixture = JSON.parse(
    await php(`
    $options = json_decode(base64_decode("${options}"), TRUE);
    $factory = \\Drupal::configFactory();
    $original = [];
    foreach (["simple_oauth.settings", "simple_oauth_native_apps.settings", "simple_oauth_pkce.settings"] as $name) {
      $original[$name] = $factory->getEditable($name)->getRawData();
    }
    $owners = \\Drupal::entityTypeManager()->getStorage("user")->loadByProperties(["name" => $options["username"]]);
    $owner = reset($owners);
    \\Drupal::currentUser()->setAccount($owner);
    $directory = sys_get_temp_dir() . "/oauth-e2e-" . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    \\Drupal::service("simple_oauth.key.generator")->generateKeys($directory);
    $factory->getEditable("simple_oauth.settings")
      ->set("private_key", $directory . "/private.key")
      ->set("public_key", $directory . "/public.key")
      ->set("disable_openid_connect", FALSE)->save();
    $factory->getEditable("simple_oauth_native_apps.settings")
      ->set("enforce_native_security", TRUE)
      ->set("native.enhanced_pkce", "enhanced")->save();
    $factory->getEditable("simple_oauth_pkce.settings")
      ->set("enforcement", "optional")
      ->set("s256_enabled", TRUE)->set("plain_enabled", TRUE)->save();
    $owned_scopes = [];
    $device_scope = "e2e:device:" . bin2hex(random_bytes(6));
    foreach (["openid", "profile", "email", $device_scope] as $name) {
      $scope = \\Drupal::service("simple_oauth.oauth2_scope.provider")->loadByName($name);
      if (!$scope) {
        $scope = \\Drupal\\simple_oauth\\Entity\\Oauth2Scope::create([
          "name" => $name,
          "description" => "E2E account access",
          "grant_types" => ["authorization_code" => ["status" => TRUE], $options["deviceGrant"] => ["status" => TRUE]],
          "umbrella" => FALSE,
          "granularity_id" => \\Drupal\\simple_oauth\\Oauth2ScopeInterface::GRANULARITY_PERMISSION,
          "granularity_configuration" => ["permission" => "access content"],
        ]);
        $scope->save();
        $owned_scopes[] = $scope->id();
      }
    }
    $redirect = $options["baseURL"] . "/.well-known/oauth-authorization-server";
    $consumer = \\Drupal\\consumers\\Entity\\Consumer::create([
      "label" => "E2E native device client",
      "client_id" => $options["username"],
      "confidential" => FALSE,
      "native_app_override" => "native",
      "grant_types" => ["authorization_code", "refresh_token", $options["deviceGrant"]],
      "redirect" => [$redirect],
    ]);
    $violations = $consumer->validate();
    if ($violations->count()) {
      throw new \\RuntimeException((string) $violations);
    }
    $consumer->save();
    print json_encode([
      "id" => $consumer->id(), "clientId" => $consumer->getClientId(),
      "redirect" => $redirect, "deviceScope" => $device_scope,
      "original" => $original, "directory" => $directory, "ownedScopes" => $owned_scopes,
    ]);
  `),
  );
});

test.afterAll(async () => {
  if (fixture) {
    const encoded = Buffer.from(JSON.stringify(fixture)).toString('base64');
    await php(`
      $fixture = json_decode(base64_decode("${encoded}"), TRUE);
      $manager = \\Drupal::entityTypeManager();
      foreach (["oauth2_token" => ["client" => $fixture["id"]], "oauth2_device_code" => ["client_id" => $fixture["clientId"]]] as $type => $conditions) {
        $storage = $manager->getStorage($type);
        $storage->delete($storage->loadByProperties($conditions));
      }
      $manager->getStorage("consumer")->load($fixture["id"])?->delete();
      foreach ($fixture["ownedScopes"] as $id) {
        $manager->getStorage("oauth2_scope")->load($id)?->delete();
      }
      foreach ($fixture["original"] as $name => $values) {
        \\Drupal::configFactory()->getEditable($name)->setData($values)->save();
      }
      \\Drupal::service("file_system")->deleteRecursive($fixture["directory"]);
    `);
  }
  if (user) {
    await execDrushInTestSite(
      `user:cancel ${user.username} --delete-content -y`,
    );
  }
});

test('native PKCE defaults protect a complete signed OIDC exchange', async ({
  page,
  request,
}) => {
  await login(page);
  for (const absent of ['missing', 'null']) {
    await php(`
      $config = \\Drupal::configFactory()->getEditable("simple_oauth_native_apps.settings");
      ${absent === 'missing' ? '$config->clear("native.enforce");' : '$config->set("native.enforce", NULL);'}
      $config->save();
    `);
    const verifier = randomBytes(48).toString('base64url');
    const challenge = createHash('sha256').update(verifier).digest('base64url');
    const parameters = {
      response_type: 'code',
      client_id: fixture.clientId,
      redirect_uri: fixture.redirect,
      scope: 'openid profile email',
      code_challenge: verifier,
    };
    for (const method of [undefined, 'plain']) {
      const error = await jsonResponse(
        await page.request.get('/oauth/authorize', {
          params: {
            ...parameters,
            ...(method ? { code_challenge_method: method } : {}),
          },
        }),
        400,
      );
      expect(error.error).toBe('invalid_request');
      expect(error.error_description).toContain('S256');
    }
    const state = randomBytes(16).toString('hex');
    const query = new URLSearchParams({
      ...parameters,
      state,
      code_challenge: challenge,
      code_challenge_method: 'S256',
    });
    await page.goto(`/oauth/authorize?${query}`);
    const consent = page.getByRole('button', { name: 'Allow', exact: true });
    if (absent === 'missing') {
      await expect(consent).toBeVisible();
    }
    if (!new URL(page.url()).searchParams.has('code')) {
      await consent.click();
    }
    await page.waitForURL(url => url.searchParams.has('code'));
    const callback = new URL(page.url());
    expect(callback.searchParams.get('state')).toBe(state);
    const exchange = {
      grant_type: 'authorization_code',
      client_id: fixture.clientId,
      redirect_uri: fixture.redirect,
      code: callback.searchParams.get('code'),
    };
    const rejected = await jsonResponse(
      await request.post('/oauth/token', {
        form: {
          ...exchange,
          code_verifier: randomBytes(48).toString('base64url'),
        },
      }),
      400,
    );
    expect(rejected.error).toBe('invalid_grant');
    const tokens = await jsonResponse(
      await request.post('/oauth/token', {
        form: { ...exchange, code_verifier: verifier },
      }),
      200,
    );
    const [header, payload, signature] = tokens.id_token.split('.');
    const claims = JSON.parse(Buffer.from(payload, 'base64url').toString());
    const discovery = await jsonResponse(
      await request.get('/.well-known/openid-configuration'),
      200,
    );
    const jwks = await jsonResponse(
      await request.get(new URL(discovery.jwks_uri).pathname),
      200,
    );
    expect(JSON.parse(Buffer.from(header, 'base64url').toString()).alg).toBe(
      'RS256',
    );
    expect(
      verify(
        'RSA-SHA256',
        Buffer.from(`${header}.${payload}`),
        createPublicKey({ key: jwks.keys[0], format: 'jwk' }),
        Buffer.from(signature, 'base64url'),
      ),
    ).toBe(true);
    expect(claims.name).toBe(user.username);
    expect(claims.preferred_username).toBe(user.username);
    expect(claims.email).toBe(user.email);
    expect(claims.email_verified).toBe(true);
    expect(claims.iss).toBe(discovery.issuer);
    const replay = await jsonResponse(
      await request.post('/oauth/token', {
        form: { ...exchange, code_verifier: verifier },
      }),
      400,
    );
    expect(replay.error).toBe('invalid_grant');
    const inspect = { client_id: fixture.clientId, token: tokens.access_token };
    expect(
      (
        await jsonResponse(
          await request.post('/oauth/introspect', {
            headers: { Authorization: `Bearer ${tokens.access_token}` },
            form: inspect,
          }),
          200,
        )
      ).active,
    ).toBe(true);
    const revoked = await request.post('/oauth/revoke', { form: inspect });
    expect(revoked.status()).toBe(200);
    expect(await revoked.text()).toBe('');
    const revokedAuthentication = await request.post('/oauth/introspect', {
      headers: { Authorization: `Bearer ${tokens.access_token}` },
      form: inspect,
    });
    expect(revokedAuthentication.status()).toBe(401);
    expect(revokedAuthentication.headers()['www-authenticate']).toContain(
      'Bearer',
    );
  }
});

test('device consent binds reviewed access and prevents replay or denied redemption', async ({
  page,
  request,
}) => {
  await login(page);
  for (const allow of [true, false]) {
    const device = await jsonResponse(
      await request.post('/oauth/device_authorization', {
        form: { client_id: fixture.clientId, scope: fixture.deviceScope },
      }),
      200,
    );
    await page.goto(device.verification_uri_complete);
    await expect(
      page.getByRole('button', { name: 'Authorize Device', exact: true }),
    ).toHaveCount(0);
    await page
      .getByRole('button', { name: 'Review request', exact: true })
      .click();
    await expect(page.locator('body')).toContainText(
      'E2E native device client',
    );
    await expect(page.locator('body')).toContainText(fixture.deviceScope);
    await page
      .getByRole('button', {
        name: allow ? 'Authorize Device' : 'Deny Access',
        exact: true,
      })
      .click();
    await expect(page.locator('body')).toContainText(
      allow ? 'Device authorized successfully' : 'denied',
    );
    const poll = {
      grant_type: deviceGrant,
      client_id: fixture.clientId,
      device_code: device.device_code,
    };
    const result = await jsonResponse(
      await request.post('/oauth/token', { form: poll }),
      allow ? 200 : 401,
    );
    if (allow) {
      expect(result.access_token).toBeTruthy();
      const replay = await jsonResponse(
        await request.post('/oauth/token', { form: poll }),
        400,
      );
      expect(['invalid_grant', 'invalid_request']).toContain(replay.error);
      if (replay.error === 'invalid_request') {
        expect(replay.hint).toContain('revoked');
      }
      expect(replay.access_token).toBeUndefined();
      expect(replay.refresh_token).toBeUndefined();
    } else {
      expect(result.error).toBe('access_denied');
    }
  }
});

test('registration management authenticates, rotates credentials and deletes owned records', async ({
  request,
}) => {
  const metadata = {
    client_name: 'E2E registered client',
    application_type: 'native',
    redirect_uris: ['org.example.e2e://callback'],
    grant_types: ['authorization_code', deviceGrant],
    token_endpoint_auth_method: 'client_secret_basic',
  };
  const registration = await jsonResponse(
    await request.post('/oauth/register', { data: metadata }),
    201,
  );
  const path = new URL(registration.registration_client_uri).pathname;
  const headers = {
    Authorization: `Bearer ${registration.registration_access_token}`,
  };
  try {
    expect(registration.token_endpoint_auth_method).toBe('client_secret_basic');
    expect(registration.client_secret).toBeTruthy();
    const denied = await jsonResponse(await request.get(path), 400);
    expect(denied.error).toBe('invalid_request');
    const read = await jsonResponse(await request.get(path, { headers }), 200);
    expect(read.client_id).toBe(registration.client_id);
    const updated = await jsonResponse(
      await request.put(path, {
        headers,
        data: { ...metadata, client_name: 'Updated E2E registration' },
      }),
      200,
    );
    expect(updated.client_name).toBe('Updated E2E registration');
    const rotated = await jsonResponse(
      await request.post(path, { headers }),
      200,
    );
    expect(rotated.client_secret).toBeTruthy();
    expect(rotated.client_secret).not.toBe(registration.client_secret);
    const authorization = secret =>
      `Basic ${Buffer.from(`${registration.client_id}:${secret}`).toString('base64')}`;
    const oldSecret = await jsonResponse(
      await request.post('/oauth/device_authorization', {
        headers: { Authorization: authorization(registration.client_secret) },
      }),
      401,
    );
    expect(oldSecret.error).toBe('invalid_client');
    await jsonResponse(
      await request.post('/oauth/device_authorization', {
        headers: { Authorization: authorization(rotated.client_secret) },
      }),
      200,
    );
    expect((await request.delete(path, { headers })).status()).toBe(204);
    const deleted = await jsonResponse(
      await request.get(path, { headers }),
      400,
    );
    expect(deleted.error).toBe('invalid_request');
  } finally {
    const encoded = Buffer.from(registration.client_id).toString('base64');
    await php(`
      $id = base64_decode("${encoded}");
      $manager = \\Drupal::entityTypeManager();
      $storage = $manager->getStorage("oauth2_device_code");
      $storage->delete($storage->loadByProperties(["client_id" => $id]));
      foreach ($manager->getStorage("consumer")->loadByProperties(["client_id" => $id]) as $consumer) {
        $consumer->delete();
      }
    `);
  }
});
