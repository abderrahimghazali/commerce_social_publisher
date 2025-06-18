<?php

namespace Drupal\commerce_social_publisher\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Database\Connection;
use Drupal\file\Entity\File;
use Drupal\commerce_product\Entity\ProductInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Social Publisher Form.
 */
class SocialPublisherForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new SocialPublisherForm.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, QueueFactory $queue_factory, Connection $database) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->queueFactory = $queue_factory;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('queue'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_social_publisher_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ProductInterface $commerce_product = NULL) {
    if (!$commerce_product) {
      $this->messenger()->addError($this->t('Product not found.'));
      return $form;
    }

    // Store the product in form state
    $form_state->set('product', $commerce_product);

    $form['product_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Product Information'),
      '#collapsible' => FALSE,
    ];

    $form['product_info']['product_title'] = [
      '#type' => 'item',
      '#title' => $this->t('Product'),
      '#markup' => $commerce_product->getTitle(),
    ];

    $form['platforms'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Social Media Platforms'),
      '#description' => $this->t('Select which platforms to publish to.'),
      '#options' => [
        'facebook' => $this->t('Facebook'),
        'instagram' => $this->t('Instagram'),
      ],
      '#required' => TRUE,
    ];

    $form['image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Image'),
      '#description' => $this->t('Upload an image to share with the post. If no image is uploaded, the product\'s default image will be used.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['png gif jpg jpeg'],
        'file_validate_size' => [5 * 1024 * 1024], // 5MB max
      ],
      '#upload_location' => 'public://social_media_images/',
    ];

    // Get default message template
    $config = $this->config('commerce_social_publisher.settings');
    $default_template = $config->get('default_message_template') ?: 'Check out this amazing product: [product:title]';

    // Replace tokens in default message
    $token_service = \Drupal::token();
    $default_message = $token_service->replace($default_template, [
      'product' => $commerce_product,
    ]);

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#description' => $this->t('Enter the message to post on social media. You can use tokens like [product:title], [product:url].'),
      '#default_value' => $default_message,
      '#required' => TRUE,
      '#rows' => 4,
    ];

    $form['schedule'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Scheduling'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['schedule']['schedule_post'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Schedule this post for later'),
    ];

    $form['schedule']['scheduled_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Scheduled Date & Time'),
      '#description' => $this->t('Select when to publish this post.'),
      '#default_value' => date('Y-m-d\TH:i', strtotime('+1 hour')),
      '#states' => [
        'visible' => [
          ':input[name="schedule_post"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="schedule_post"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Share Product'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $commerce_product->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $schedule_post = $form_state->getValue('schedule_post');

    if ($schedule_post) {
      $scheduled_date = $form_state->getValue('scheduled_date');
      if ($scheduled_date && $scheduled_date->getTimestamp() <= time()) {
        $form_state->setErrorByName('scheduled_date', $this->t('Scheduled date must be in the future.'));
      }
    }

    // Validate platforms
    $platforms = array_filter($form_state->getValue('platforms'));
    if (empty($platforms)) {
      $form_state->setErrorByName('platforms', $this->t('Please select at least one platform.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $product = $form_state->get('product');
    $platforms = array_filter($form_state->getValue('platforms'));
    $message = $form_state->getValue('message');
    $schedule_post = $form_state->getValue('schedule_post');
    $image_fid = NULL;

    // Handle image upload
    $image = $form_state->getValue('image');
    if (!empty($image[0])) {
      $file = File::load($image[0]);
      if ($file) {
        $file->setPermanent();
        $file->save();
        $image_fid = $file->id();
      }
    }

    // Prepare post data
    $post_data = [
      'product_id' => $product->id(),
      'user_id' => $this->currentUser->id(),
      'platforms' => json_encode(array_values($platforms)),
      'message' => $message,
      'image_fid' => $image_fid,
      'status' => 'pending',
      'created' => time(),
    ];

    if ($schedule_post) {
      $scheduled_date = $form_state->getValue('scheduled_date');
      $post_data['scheduled_time'] = $scheduled_date->getTimestamp();
    }

    // Save to database
    $post_id = $this->database->insert('commerce_social_publisher_posts')
      ->fields($post_data)
      ->execute();

    // Add to queue for processing
    $queue = $this->queueFactory->get('social_media_publisher');
    $queue_data = [
      'post_id' => $post_id,
      'action' => $schedule_post ? 'schedule' : 'publish_now',
    ];

    $queue->createItem($queue_data);

    if ($schedule_post) {
      $this->messenger()->addMessage($this->t('Your post has been scheduled successfully.'));
    } else {
      $this->messenger()->addMessage($this->t('Your post is being published to the selected platforms.'));
    }

    // Redirect back to product page
    $form_state->setRedirect('entity.commerce_product.canonical', [
      'commerce_product' => $product->id(),
    ]);
  }

}
