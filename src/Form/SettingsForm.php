<?php

namespace Drupal\chip_api\Form;

use Drupal\chip_api\ChipApi;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Chip Api for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'chip_api.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chip_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $username = ChipApi::getUsername();
    $password = ChipApi::getPassword();

    $form['description'] = [
      '#markup' => $this->t('You must be registered as an <a href=":url">Associate with Chip</a> before using this module.', [':url' => 'https://www.bestcheck.de/']),
    ];

    $form['basic_auth'] = [
      '#type' => 'fieldset',
      '#title' => t('HTTP authentication'),
    ];

    $description = $this->t('Enter your username.');
    if (empty($username)) {
      $description = $this->t('You must sign up for a BestCheck account.');
    }
    $form['basic_auth'][ChipApi::SETTINGS_BA_USERNAME] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#default_value' => $username,
      '#description' => $description,
    ];

    $description = $this->t('Enter your password.');
    if (empty($password)) {
      $description = $this->t('You must sign up for a BestCheck account.');
    }
    $form['basic_auth'][ChipApi::SETTINGS_BA_PASSWORD] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#required' => TRUE,
      '#default_value' => $password,
      '#description' => $description,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $config = $this->config('chip_api.settings');

    foreach (ChipApi::getAvailableSettingsKeys() as $settings_key) {
      if (!ChipApi::isSetInEnv($settings_key)) {
        $config->set($settings_key, $form_state->getValue($settings_key));
      }
    }

    $config->save();
  }

}
