<?php

declare(strict_types=1);

namespace Drupal\yse_connectrequester\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for YSE Connect Requester settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'yse_connectrequester_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['yse_connectrequester.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('yse_connectrequester.settings');

    $form['api_secret'] = [
      '#type' => 'textarea',
      '#title' => $this->t('YaleConnect API Secret'),
      '#description' => $this->t('The value sent as the <code>X-CG-API-Secret</code> request header.'),
      '#default_value' => $config->get('api_secret'),
      '#rows' => 3,
      '#required' => TRUE,
    ];

    $form['group_type_ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group Type IDs'),
      '#description' => $this->t('Comma-separated YaleConnect group type IDs (e.g. <code>4951287,35203</code>).'),
      '#default_value' => $config->get('group_type_ids'),
      '#required' => TRUE,
    ];

    $form['cutoff_days_email'] = [
      '#type' => 'number',
      '#title' => $this->t('Cutoff days — Daily Events Email'),
      '#description' => $this->t('Fetch events starting within this many days (used by the standard preview).'),
      '#default_value' => $config->get('cutoff_days_email'),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['cutoff_days_filtered'] = [
      '#type' => 'number',
      '#title' => $this->t('Cutoff days — Daily Events Email (Filtered)'),
      '#description' => $this->t('Fetch events starting within this many days (used by the filtered preview).'),
      '#default_value' => $config->get('cutoff_days_filtered'),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['cache_ttl_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (minutes)'),
      '#description' => $this->t('How long to cache the YaleConnect API response and the rendered preview page. Set to 0 to disable caching.'),
      '#default_value' => $config->get('cache_ttl_minutes'),
      '#min' => 0,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('yse_connectrequester.settings')
      ->set('api_secret', trim($form_state->getValue('api_secret')))
      ->set('group_type_ids', $form_state->getValue('group_type_ids'))
      ->set('cutoff_days_email', (int) $form_state->getValue('cutoff_days_email'))
      ->set('cutoff_days_filtered', (int) $form_state->getValue('cutoff_days_filtered'))
      ->set('cache_ttl_minutes', (int) $form_state->getValue('cache_ttl_minutes'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
