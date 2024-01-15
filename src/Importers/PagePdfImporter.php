<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Importers;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\pathauto\PathautoState;
use Drupal\vneml_migrate_content\DataFormatter;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class PagePdfImporter extends AbstractImporter
{
  private const IMAGE_DIRECTORIES = [
    DRUPAL_ROOT . '/../exports/files',
  ];

  /**
   * @var string
   */
  private $nodeType;

  /**
   * @var DataFormatter
   */
  private $formatter;

  /**
   * @var QueueInterface
   */
  private $imageUploadQueue;

  /**
   * @var EntityStorageInterface
   */
  private $nodeStorage;

  /**
   * @var LoggerInterface
   */
  private $logger;

  /**
   * @var array
   */
  private $nodeIds = [];

  /**
   * @var array
   */
  private $missingImages = [];

  /**
   * @param string $nodeType
   * @param DataFormatter $formatter
   *
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function __construct(string $nodeType, DataFormatter $formatter)
  {
    $this->nodeType = $nodeType;
    $this->formatter = $formatter;
    $this->imageUploadQueue = Drupal::queue('vneml_image_uploader');
    $this->nodeStorage = Drupal::entityTypeManager()->getStorage('node');

    $this->logger = new Logger(
      sprintf('vneml_migrate_content_%s', $this->nodeType),
      [
        new StreamHandler(
          sprintf(
            '%s/modules/custom/vneml_migrate_content/logs/vneml_migrate_content_%s.log',
            DRUPAL_ROOT,
            $this->nodeType
          )
        ),
      ]
    );
  }

  public function preImport(): void
  {
    $this->logger->info('Starting new import.');

    $query = Drupal::database()->select('node__field_import_id', 'nid');
    $query->join('node', 'n', 'n.nid = nid.entity_id');
    $query->fields('n', ['nid'])
      ->fields('nid', ['field_import_id_value'])
      ->condition('n.type', $this->nodeType);

    foreach ($query->execute()->fetchAll() as $node) {
      $this->nodeIds[$node->field_import_id_value] = (int) $node->nid;
    }
  }

  public function postImport(): void
  {
    if (!empty($this->missingImages)) {
      $this->logger->warning('Some images could not be found.', ['images' => implode(',', array_values($this->missingImages))]);
    }

    $this->logger->info('Import finished.');
  }

  /**
   * @param array $data
   *
   * @throws Exception
   */
  protected function handleRecord(array $data): void
  {
    $nodeId = $this->nodeIds[$data['nid']] ?? null;

    if (null !== $nodeId) {
      /** @var Node $node */
      $node = Node::load($nodeId);

      if ($node->hasTranslation($data['taal'])) {
        $node = $node->getTranslation($data['taal']);
      } else {
        $node = $node->addTranslation($data['taal']);
      }
    } else {
      /** @var Node $node */
      $node = Node::create(['type' => $this->nodeType, 'langcode' => $data['taal']]);
    }

    $this->fillNode($node, $data);

    try {
      $node->save();

      $this->nodeIds[$data['nid']] = $node->id();

      $this->logger->info('Page successfully imported.', ['nid' => $data['nid']]);
    } catch (EntityStorageException $exception) {
      $this->logger->error('Error saving entity.', ['nid' => $data['nid'], 'error' => $exception->getMessage()]);
    }
  }

  /**
   * @param Node $node
   * @param array $data
   *
   * @throws Exception
   */
  private function fillNode(Node $node, array $data): void
  {
    $node->set('status', $data['status']);
    $node->set('field_import_id', $data['nid']);
    $node->set('title', $data['titel']);
    $node->set('field_visible_title', [
      'format' => 'full_html',
      'value' => '<h1>' . $data['titel'] . '</h1>',
    ]);

    $node->set('path', ['alias' => '/' . $data['url'], 'pathauto' => PathautoState::SKIP]);
    $node->set('field_meta_tags', serialize(['description' => $data['metatag_description']]));

    $fieldContent = $node->get('field_content')->getValue();

    if (!empty($fieldContent[0])) {
      $paragraph = Paragraph::load($fieldContent[0]['target_id']);
    } else {
      $paragraph = Paragraph::create(['type' => 'text', 'langcode' => $data['taal']]);
    }

    $paragraph->set(
      'field_text',
      [
        'format' => 'full_html',
        'value' => html_entity_decode($data['body']),
      ]
    );
    $paragraph->save();

    $fieldContent[0] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];

    if (!empty($data['pdf_bestand'])) {
      if (!empty($fieldContent[1])) {
        $pdf = Paragraph::load($fieldContent[1]['target_id']);
      } else {
        $pdf = Paragraph::create(['type' => 'pdf', 'langcode' => $data['taal']]);
      }

      $pdf_file = $this->addPdf($node, $data);

      if ($pdf_file) {
        $pdf->set('field_pdf', $pdf_file);
        $pdf->save();

        $fieldContent[1] = [
          'target_id' => $pdf->id(),
          'target_revision_id' => $pdf->getRevisionId(),
        ];
      }
    }
    $node->set('field_content', $fieldContent);
  }

  /**
   * @param Node $node
   * @param array $data
   * @return Drupal\file\FileInterface|false
   */
  private function addPdf (Node $node, array $data)
  {

    $data_pdf_bestand = static::IMAGE_DIRECTORIES[0] .'/'. str_replace('public://', '', $data['pdf_bestand']);

    $directory = sprintf('public://%s', $node->get('type')->getString());
    Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

    return file_save_data(
      file_get_contents($data_pdf_bestand),
      sprintf('public://%s/%s', $node->get('type')->getString(), basename($data_pdf_bestand)),
      FileSystemInterface::EXISTS_REPLACE
    );

  }

}
