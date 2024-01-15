<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Importers;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\Entity\User;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class UserImporter extends AbstractImporter
{
  /**
   * @var LoggerInterface
   */
  private $logger;

  /**
   * @var array
   */
  private $uids;

  public function __construct()
  {
    $this->logger = new Logger(
      'vneml_migrate_content_user',
      [
        new StreamHandler(
          sprintf('%s/modules/custom/vneml_migrate_content/logs/vneml_migrate_content_user.log', DRUPAL_ROOT)
        ),
      ]
    );

    $users = Drupal::database()->select('users_field_data', 'ufd')
      ->fields('ufd', ['uid', 'mail'])
      ->execute()
      ->fetchAll();

    foreach ($users as $user) {
      $this->uids[] = strtolower((string) $user->mail);
    }
  }



  /**
   * @param array $data
   *
   * @throws EntityStorageException
   */
  protected function handleRecord(array $data): void
  {
    if(in_array(strtolower((string) $data['mail']), $this->uids)) {
      return;
    }
    /** @var User $user */
    $user = User::create();

    $user->setUsername($data['name']);
    $user->setEmail($data['mail']);
    $user->setLastAccessTime((int) $data['access']);
    $user->setLastLoginTime((int) $data['login']);

    $user->set('created', (int) $data['created']);
    $user->set('status', $data['status']);
    $user->set('timezone', $data['timezone']);
    $user->set('init', $data['init']);
    $user->set('field_imported', 1);

    try {
      $user->save();

      $this->logger->info('User successfully imported.', ['uid' => $data['uid']]);
    } catch (Exception $exception) {
      $this->logger->error('Error saving entity.', ['uid' => $data['uid'], 'error' => $exception->getMessage()]);
    }
  }
}
