<?php

namespace Drupal\commerce_social_publisher\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Commerce Social Publisher settings.
 */
class SocialPublisherSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_social_publisher_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_social_publisher.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_social_publisher.settings');

    $form['platforms'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Available Platforms'),
    ];

    $form['platforms']['enabled_platforms'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled Platforms'),
      '#options' => [
        'facebook' => $this->t('Facebook'),
        'instagram' => $this->t('Instagram'),
      ],
      '#default_value' => $config->get('enabled_platforms') ?: [],
      '#description' => $this->t('Select which social media platforms are available for publishing.'),
    ];

    $form['facebook'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Facebook Configuration'),
      '#states' => [
        'visible' => [
          ':input[name="enabled_platforms[facebook]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['facebook']['facebook_app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook App ID'),
      '#default_value' => $config->get('facebook_app_id'),
      '#description' => $this->t('Your Facebook App ID from Meta for Developers.'),
    ];

    $form['facebook']['facebook_app_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Facebook App Secret'),
      '#description' => $this->t('Your Facebook App Secret. Leave blank to keep current value.'),
    ];

    $form['facebook']['facebook_page_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook Page ID'),
      '#default_value' => $config->get('facebook_page_id'),
      '#description' => $this->t('The ID of the Facebook page to post to.'),
    ];

    $form['facebook']['facebook_access_token'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Facebook Page Access Token'),
      '#default_value' => $config->get('facebook_access_token'),
      '#description' => $this->t('Long-lived page access token for posting to Facebook.'),
      '#rows' => 3,
    ];

    $form['instagram'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Instagram Configuration'),
      '#states' => [
        'visible' => [
          ':input[name="enabled_platforms[instagram]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['instagram']['instagram_account_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Instagram Business Account ID'),
      '#default_value' => $config->get('instagram_account_id'),
      '#description' => $this->t('Your Instagram Business Account ID.'),
    ];

    $form['instagram']['instagram_access_token'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Instagram Access Token'),
      '#default_value' => $config->get('instagram_access_token'),
      '#description' => $this->t('Access token for Instagram Business API.'),
      '#rows' => 3,
    ];

    $form['messages'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default Messages'),
    ];

    $form['messages']['default_message_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Message Template'),
      '#default_value' => $config->get('default_message_template') ?: 'Check out this amazing product: [product:title]',
      '#description' => $this->t('Default message template. You can use tokens like [product:title], [product:url], etc.'),
      '#rows' => 3,
    ];

    $form['api_help'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Setup Help'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['api_help']['help_text'] = [
      '#type' => 'markup',
      '#markup' => '<div>' .
        '<h4>' . $this->t('Setting up Facebook/Instagram API:') . '</h4>' .
        '<ol>' .
        '<li>' . $this->t('Go to <a href="https://developers.facebook.com" target="_blank">Meta for Developers</a>') . '</li>' .
        '<li>' . $this->t('Create a new app or use an existing one') . '</li>' .
        '<li>' . $this->t('Add Facebook Login and Instagram Basic Display products') . '</li>' .
        '<li>' . $this->t('Generate a long-lived page access token') . '</li>' .
        '<li>' . $this->t('For Instagram, ensure your account is a Business account connected to a Facebook page') . '</li>' .
        '</ol>' .
        '</div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('commerce_social_publisher.settings');

    $config
      ->set('enabled_platforms', array_filter($form_state->getValue('enabled_platforms')))
      ->set('facebook_app_id', $form_state->getValue('facebook_app_id'))
      ->set('facebook_page_id', $form_state->getValue('facebook_page_id'))
      ->set('facebook_access_token', $form_state->getValue('facebook_access_token'))
      ->set('instagram_account_id', $form_state->getValue('instagram_account_id'))
      ->set('instagram_access_token', $form_state->getValue('instagram_access_token'))
      ->set('default_message_template', $form_state->getValue('default_message_template'));

    // Only update app secret if a new value was provided
    $app_secret = $form_state->getValue('facebook_app_secret');
    if (!empty($app_secret)) {
      $config->set('facebook_app_secret', $app_secret);
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
