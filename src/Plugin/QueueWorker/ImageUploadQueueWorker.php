<?php

namespace Drupal\vneml_migrate_content\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\Entity\Node;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * @QueueWorker(
 *   id = "vneml_image_uploader",
 *   title = @Translation("VNEML Image Uploader"),
 *   cron = {"time" = 60}
 * )
 */
class ImageUploadQueueWorker extends QueueWorkerBase
{
  /**
   * @var LoggerInterface
   */
  private $logger;

  public function __construct(array $configuration, $plugin_id, $plugin_definition)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->logger = new Logger(
      'vneml_migrate_content_images',
      [
        new StreamHandler(
          sprintf('%s/modules/custom/vneml_migrate_content/logs/vneml_migrate_content_images.log', DRUPAL_ROOT)
        ),
      ]
    );
  }

  public function processItem($data)
  {

    if(empty($data->nodeId)) {
      return false;
    }

    try {
      /** @var Node $node */
      $node = Node::load($data->nodeId);

      $directory = sprintf('s3://%s', $node->get('type')->getString());

      Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

      if (!empty($data->images)) {

        $processedImages = [];

        foreach ($data->images as $image) {
          $file = file_save_data(
            file_get_contents($image),
            sprintf('s3://%s/%s', $node->get('type')->getString(), basename($image)),
            FileSystemInterface::EXISTS_REPLACE
          );
          $processedImages[] = $file;
        }

        $node->set('field_images', $processedImages);
      }

      if (!empty($data->image)) {
        $file = file_save_data(
          file_get_contents($data->image),
          sprintf('s3://%s/%s', $node->get('type')->getString(), basename($data->image)),
          FileSystemInterface::EXISTS_REPLACE
        );

        $node->set('field_image', $file);
      }

      return $node->save();

    } catch (Exception $exception) {

        \Drupal::logger('vneml_api')->error($exception->getMessage());
      $this->logger->error(
        'Error processing image upload.',
        [
          'nodeId' => $data->nodeId,
          'error' => $exception->getMessage(),
        ]
      );
    }

    return false;
  }
}
