<?php

namespace Drupal\chip_api;

/**
 * Chip Api service trait.
 */
trait ChipApiTrait {

  /**
   * The Chip API Service.
   *
   * @var \Drupal\chip_api\ChipApi
   */
  protected $chipApi;

  /**
   * Gets the Chip Api Service.
   *
   * @return \Drupal\chip_api\ChipApi
   */
  public function getChipApi() {
    if (empty($this->chipApi)) {
      $this->chipApi = \Drupal::service('chip_api.chip_api');
    }
    return $this->chipApi;
  }

}
