<?php

namespace Drupal\commerce_social_publisher\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\commerce_social_publisher\Service\SocialMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes social media publishing queue items.
 *
 * @QueueWorker(
 *   id = "social_media_publisher",
 *   title = @Translation("Social Media Publisher"),
 *   cron = {"time" = 60}
 * )
 */
class SocialMediaPublisher extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The social media manager service.
   *
   * @var \Drupal\commerce_social_publisher\Service\SocialMediaManager
   */
  protected $socialMediaManager;

  /**
   * Constructs a new SocialMediaPublisher.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database, LoggerChannelFactoryInterface $logger_factory, SocialMediaManager $social_media_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
    $this->socialMediaManager = $social_media_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('commerce_social_publisher.social_media_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $post_id = $data['post_id'];
    $action = $data['action'];

    // Load post data.
    $post = $this->database->select('commerce_social_publisher_posts', 'p')
      ->fields('p')
      ->condition('id', $post_id)
      ->execute()
      ->fetchAssoc();

    if (!$post) {
      $this->loggerFactory->get('commerce_social_publisher')
        ->error('Post not found: @id', ['@id' => $post_id]);
      return;
    }

    // Check if it's a scheduled post and if it's time to publish.
    if ($action === 'schedule' && $post['scheduled_time'] > time()) {
      // Not time yet, re-queue for later
      $queue = \Drupal::queue('social_media_publisher');
      $queue->createItem($data);
      return;
    }

    try {
      // Publish to social media platforms.
      $platforms = json_decode($post['platforms'], TRUE);
      $success = $this->socialMediaManager->publishPost($post, $platforms);

      if ($success) {
        // Update status to published
        $this->database->update('commerce_social_publisher_posts')
          ->fields([
            'status' => 'published',
            'published' => time(),
          ])
          ->condition('id', $post_id)
          ->execute();

        $this->loggerFactory->get('commerce_social_publisher')
          ->info('Successfully published post @id', ['@id' => $post_id]);
      } else {
        throw new \Exception('Failed to publish to one or more platforms');
      }
    }
    catch (\Exception $e) {
      // Update status to failed.
      $this->database->update('commerce_social_publisher_posts')
        ->fields(['status' => 'failed'])
        ->condition('id', $post_id)
        ->execute();

      $this->loggerFactory->get('commerce_social_publisher')
        ->error('Failed to publish post @id: @error', [
          '@id' => $post_id,
          '@error' => $e->getMessage(),
        ]);

      throw $e;
    }
  }

}
