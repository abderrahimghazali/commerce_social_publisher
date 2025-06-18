<?php

namespace Drupal\commerce_social_publisher\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\commerce_product\Entity\Product;
use GuzzleHttp\ClientInterface;

/**
 * Social Media Manager Service.
 */
class SocialMediaManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new SocialMediaManager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Publishes a post to specified social media platforms.
   *
   * @param array $post
   *   The post data.
   * @param array $platforms
   *   Array of platform names.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function publishPost(array $post, array $platforms) {
    $success = TRUE;
    $config = $this->configFactory->get('commerce_social_publisher.settings');

    foreach ($platforms as $platform) {
      try {
        switch ($platform) {
          case 'facebook':
            $this->publishToFacebook($post, $config);
            break;

          case 'instagram':
            $this->publishToInstagram($post, $config);
            break;
        }
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('commerce_social_publisher')
          ->error('Failed to publish to @platform: @error', [
            '@platform' => $platform,
            '@error' => $e->getMessage(),
          ]);
        $success = FALSE;
      }
    }

    return $success;
  }

  /**
   * Publishes to Facebook using Graph API.
   *
   * @param array $post
   *   The post data.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   */
  protected function publishToFacebook(array $post, $config) {
    $app_id = $config->get('facebook_app_id');
    $app_secret = $config->get('facebook_app_secret');
    $page_id = $config->get('facebook_page_id');
    $access_token = $config->get('facebook_access_token');

    if (!$app_id || !$app_secret || !$page_id || !$access_token) {
      throw new \Exception('Facebook API credentials not configured');
    }

    $product = Product::load($post['product_id']);
    if (!$product) {
      throw new \Exception('Product not found');
    }

    // Prepare post data.
    $post_data = [
      'message' => $post['message'],
      'access_token' => $access_token,
    ];

    // Add image if available.
    if ($post['image_fid']) {
      $file = File::load($post['image_fid']);
      if ($file) {
        $image_url = file_create_url($file->getFileUri());
        $post_data['picture'] = $image_url;
      }
    }

    // Add product link.
    $product_url = $product->toUrl('canonical', ['absolute' => TRUE])->toString();
    $post_data['link'] = $product_url;

    // Make API call to Facebook.
    $response = $this->httpClient->post("https://graph.facebook.com/v18.0/{$page_id}/feed", [
      'form_params' => $post_data,
    ]);

    if ($response->getStatusCode() !== 200) {
      throw new \Exception('Facebook API returned error: ' . $response->getBody());
    }

    $this->loggerFactory->get('commerce_social_publisher')
      ->info('Successfully published to Facebook for product @product', [
        '@product' => $product->getTitle(),
      ]);
  }

  /**
   * Publishes to Instagram using Graph API.
   *
   * @param array $post
   *   The post data.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   */
  protected function publishToInstagram(array $post, $config) {
    $instagram_account_id = $config->get('instagram_account_id');
    $access_token = $config->get('instagram_access_token');

    if (!$instagram_account_id || !$access_token) {
      throw new \Exception('Instagram API credentials not configured');
    }

    $product = Product::load($post['product_id']);
    if (!$product) {
      throw new \Exception('Product not found');
    }

    // Instagram requires an image.
    $image_url = NULL;
    if ($post['image_fid']) {
      $file = File::load($post['image_fid']);
      if ($file) {
        $image_url = file_create_url($file->getFileUri());
      }
    } else {
      // Try to get product's default image.
      if ($product->hasField('field_image') && !$product->get('field_image')->isEmpty()) {
        $image_entity = $product->get('field_image')->entity;
        if ($image_entity) {
          $image_url = file_create_url($image_entity->getFileUri());
        }
      }
    }

    if (!$image_url) {
      throw new \Exception('Instagram requires an image, but none was found');
    }

    // Step 1: Create media container.
    $container_data = [
      'image_url' => $image_url,
      'caption' => $post['message'],
      'access_token' => $access_token,
    ];

    $response = $this->httpClient->post("https://graph.facebook.com/v18.0/{$instagram_account_id}/media", [
      'form_params' => $container_data,
    ]);

    $container_result = json_decode($response->getBody(), TRUE);
    if (!isset($container_result['id'])) {
      throw new \Exception('Failed to create Instagram media container');
    }

    $creation_id = $container_result['id'];

    // Step 2: Publish the media.
    $publish_data = [
      'creation_id' => $creation_id,
      'access_token' => $access_token,
    ];

    $response = $this->httpClient->post("https://graph.facebook.com/v18.0/{$instagram_account_id}/media_publish", [
      'form_params' => $publish_data,
    ]);

    if ($response->getStatusCode() !== 200) {
      throw new \Exception('Instagram API returned error: ' . $response->getBody());
    }

    $this->loggerFactory->get('commerce_social_publisher')
      ->info('Successfully published to Instagram for product @product', [
        '@product' => $product->getTitle(),
      ]);
  }

}
