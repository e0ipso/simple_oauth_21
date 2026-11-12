const { test, expect } = require('@playwright/test');
const { setupUniqueAdminUser } = require('../utils/test-setup');
const { execDrushInTestSite } = require('../utils/drush-helper');
const { waitForAjax } = require('../utils/ajax-helper');

test('detects client type repeatedly without saving the consumer', async ({
  page,
}) => {
  let user;
  let consumer;
  const originalRedirect = 'com.example.app://callback';
  const changedRedirect = 'https://example.com/changed';

  try {
    await execDrushInTestSite('pm:enable simple_oauth_native_apps -y');
    user = await setupUniqueAdminUser(page, 'client_detection');
    consumer = JSON.parse(
      await execDrushInTestSite(`php:eval '
        $consumer = \\Drupal\\consumers\\Entity\\Consumer::create([
          "label" => "Client detection fixture",
          "client_id" => "${user.username}",
          "confidential" => FALSE,
          "grant_types" => ["authorization_code"],
          "redirect" => ["${originalRedirect}"],
        ]);
        $consumer->save();
        print json_encode([
          "id" => $consumer->id(),
          "edit" => $consumer->toUrl("edit-form")->toString(),
        ]);
      '`),
    );

    await page.goto(consumer.edit);
    await page.locator('#edit-native-apps > summary').click();
    await page.locator('#edit-native-apps-client-detection > summary').click();
    const results = page.locator('#client-detection-results');
    const retainedContainer = await results.elementHandle();
    const detectButton = page.getByRole('button', {
      name: 'Detect Client Type',
    });

    await detectButton.click();
    await waitForAjax(page);
    await expect(results).toContainText('Detection Result');
    await expect(results).toContainText(originalRedirect);

    // A second AJAX request must update the existing result container.
    await page.locator('[name="redirect[0][value]"]').fill(changedRedirect);
    await page.locator('[name="label[0][value]"]').fill('Unsaved client edits');
    await detectButton.click();
    await waitForAjax(page);
    await expect(results).toHaveCount(1);
    await expect(results).toContainText(changedRedirect);
    await expect(results).not.toContainText(originalRedirect);
    expect(
      await retainedContainer.evaluate(element => element.isConnected),
    ).toBe(true);

    // Reload persisted values rather than relying on the unsaved form state.
    await page.reload();
    await expect(page.locator('[name="redirect[0][value]"]')).toHaveValue(
      originalRedirect,
    );
    await expect(page.locator('[name="label[0][value]"]')).toHaveValue(
      'Client detection fixture',
    );
  } finally {
    if (consumer) {
      await execDrushInTestSite(
        `php:eval '\\Drupal\\consumers\\Entity\\Consumer::load(${Number(consumer.id)})->delete();'`,
      );
    }
    if (user) {
      await execDrushInTestSite(
        `user:cancel ${user.username} --delete-content -y`,
      );
    }
  }
});
