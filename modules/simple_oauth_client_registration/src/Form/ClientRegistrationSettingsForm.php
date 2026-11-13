<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_client_registration\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Render\Element\Checkboxes;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface;
use Drupal\simple_oauth_client_registration\Service\ClientRegistrationAdministrationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Configuration form for Dynamic Client Registration settings.
 *
 * Provides administrative interface for configuring RFC 7591 compliance
 * options and OAuth 2.1 best practices for dynamically registered clients.
 */
final class ClientRegistrationSettingsForm extends ConfigFormBase {

  /**
   * Constructs a ClientRegistrationSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface $grantManager
   *   The OAuth2 grant plugin manager.
   * @param \Drupal\simple_oauth_client_registration\Service\ClientRegistrationAdministrationInterface $registrationService
   *   The client management service.
   * @param \Symfony\Component\Serializer\Normalizer\DenormalizerInterface $normalizer
   *   The shared metadata denormalizer.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected Oauth2GrantManagerInterface $grantManager,
    protected ClientRegistrationAdministrationInterface $registrationService,
    protected DenormalizerInterface $normalizer,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('plugin.manager.oauth2_grant.processor'),
      $container->get('simple_oauth_client_registration.service.registration'),
      $container->get('serializer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_oauth_client_registration.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_oauth_client_registration_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['grant_defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Grant Type Defaults'),
      '#open' => TRUE,
      '#description' => $this->t('Configure default grant types for dynamically registered clients.'),
    ];

    $form['grant_defaults']['default_grant_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Default grant types for DCR clients'),
      '#options' => array_map(
        fn($definition) => $definition['label'],
        $this->grantManager->getDefinitions()
      ),
      '#config_target' => new ConfigTarget(
        'simple_oauth_client_registration.settings',
        'default_grant_types',
        toConfig: static fn(array $value): array => Checkboxes::getCheckedCheckboxes($value),
      ),
      '#description' => $this->t('<strong>RFC 7591 Compliance:</strong> According to RFC 7591, if a client registration request does not explicitly specify the <code>grant_types</code> parameter, the authorization server MAY establish default grant types. Select which grant types should be included in those defaults.<br><br><strong>OAuth 2.1 Best Practice:</strong> The <code>authorization_code</code> grant with <code>refresh_token</code> is recommended for maintaining secure, long-lived sessions without storing credentials. This combination provides the best security and user experience for most client applications.<br><br><strong>Override Behavior:</strong> If a client explicitly specifies <code>grant_types</code> in their registration request, that explicit list takes precedence, and this default setting is ignored.<br><br><em>References: <a href="https://datatracker.ietf.org/doc/html/rfc7591#section-2" target="_blank">RFC 7591 Section 2</a> | <a href="https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1/#section-7.2" target="_blank">OAuth 2.1 Draft Section 7.2</a></em>'),
    ];

    $form['bulk_operations'] = [
      '#type' => 'details',
      '#title' => $this->t('Bulk client management'),
      '#tree' => TRUE,
      '#open' => $form_state->has('bulk_results'),
    ];
    $form['bulk_operations']['operation'] = [
      '#type' => 'select',
      '#title' => $this->t('Operation'),
      '#options' => [
        'update' => $this->t('Update metadata'),
        'delete' => $this->t('Delete registration'),
      ],
    ];
    $form['bulk_operations']['client_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Client IDs'),
      '#description' => $this->t('Enter one dynamically registered client ID per line, up to 100. Each client is checked separately for access.'),
    ];
    $form['bulk_operations']['metadata'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Metadata to update'),
      '#description' => $this->t('JSON metadata applied to each client, for example {"client_name":"Updated name"}. Uses the same validation as single-client updates. Ignored for deletion.'),
    ];
    $form['bulk_operations']['confirm_delete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm deletion of the listed registrations'),
    ];
    $form['bulk_operations']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply to listed clients'),
      '#validate' => ['::validateBulk'],
      '#submit' => ['::submitBulk'],
    ];
    if ($form_state->has('bulk_results')) {
      $form['bulk_operations']['results'] = [
        '#type' => 'table',
        '#header' => [$this->t('Client ID'), $this->t('Result')],
        '#rows' => $form_state->get('bulk_results'),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * Validates batch input without changing registration settings.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   */
  public function validateBulk(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('bulk_operations');
    $ids = array_values(array_filter(array_map('trim', explode("\n", $values['client_ids'])), static fn(string $id): bool => $id !== ''));
    if (count($ids) < 1 || count($ids) > 100) {
      $form_state->setErrorByName('bulk_operations][client_ids', $this->t('Enter between one and 100 client IDs.'));
      return;
    }
    $operation = $values['operation'];
    if (!in_array($operation, ['update', 'delete'], TRUE)) {
      $form_state->setErrorByName('bulk_operations][operation', $this->t('Select a supported operation.'));
      return;
    }
    if ($operation === 'delete' && !(bool) $values['confirm_delete']) {
      $form_state->setErrorByName('bulk_operations][confirm_delete', $this->t('Confirm deletion before applying this operation.'));
    }
    $metadata = [];
    if ($operation === 'update') {
      try {
        $metadata = json_decode($values['metadata'], FALSE, 512, JSON_THROW_ON_ERROR);
        if (!$metadata instanceof \stdClass) {
          throw new \JsonException('Expected a metadata object');
        }
        $metadata = (array) $metadata;
      }
      catch (\JsonException) {
        $form_state->setErrorByName('bulk_operations][metadata', $this->t('Enter a JSON object containing client metadata.'));
        return;
      }
    }
    $items = [];
    foreach ($ids as $id) {
      $items[] = ['client_id' => $id, 'operation' => $operation, 'metadata' => $metadata];
    }
    $form_state->set('bulk_items', $items);
  }

  /**
   * Applies a batch and displays the corresponding outcome for every client.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   */
  public function submitBulk(array &$form, FormStateInterface $form_state): void {
    $items = $form_state->get('bulk_items');
    $results = $this->registrationService->manageClients($items, $this->currentUser(), $this->normalizer);
    $rows = [];
    foreach ($results as $index => $result) {
      $rows[] = [
        ['data' => ['#plain_text' => $items[$index]['client_id']]],
        ['data' => ['#plain_text' => $result['status']]],
      ];
    }
    $form_state->set('bulk_results', $rows)->setRebuild();
  }

}
