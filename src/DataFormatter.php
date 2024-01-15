<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

class DataFormatter
{
  /**
   * @var DateTimeZone
   */
  private $timezone;

  public function __construct()
  {
    $this->timezone = new DateTimeZone('UTC');
  }

  /**
   * @param string|null $date
   *
   * @return string|null
   * @throws Exception
   */
  public function formatDate(?string $date): ?string
  {
    return !empty($date) ? (new DateTimeImmutable($date, $this->timezone))->format('Y-m-d') : null;
  }

  /**
   * @param string|null $date
   *
   * @return int|null
   * @throws Exception
   */
  public function convertDateTimeToTimestamp(?string $date): ?int
  {
    return !empty($date) ? (new DateTimeImmutable($date, $this->timezone))->getTimestamp() : null;
  }

  /**
   * @param string|null $coordinates
   *
   * @return array|null
   */
  public function formatCoordinates(?string $coordinates): ?array
  {
    if (!empty($coordinates) && false !== preg_match('/\((.*)\)/', $coordinates, $matches)) {
      [$lng, $lat] = explode(' ', $matches[1]);

      return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
  }
}
