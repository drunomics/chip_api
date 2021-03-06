<?php

namespace Drupal\chip_api\Form;

use Drupal\chip_api\ChipApiTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;
use Drupal\facets\Exception\Exception;

/**
 * Debug Chip Api response for an ASIN.
 */
class TestForm extends FormBase {

  use ChipApiTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chip_api_test_code';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = [
      '#title' => $this->t('Chip Api response'),
    ];

    $form['description'] = [
      '#markup' => $this->t('Shows the API response for a product request.'),
    ];

    if (!empty($_SESSION['chip_api.debug.response'])) {
      // Note:  var_export uses the serialize_precision ini setting, thus
      // var_export(497.2) will output 497.19999999999999.
      $form['debug_output'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Response'),
        '#default_value' => var_export($_SESSION['chip_api.debug.response'], TRUE),
        '#rows' => 20,
        '#attributes' => [
          'disabled' => 'disabled',
        ],
      ];

      unset($_SESSION['chip_api.debug.response']);
    }

    $form['code'] = [
      '#type' => 'textfield',
      '#title' => 'ASIN / EAN code',
      '#size' => 20,
      '#maxlength' => 20,
      '#required' => TRUE,
      '#description' =>
        $this->t("<em>Amazon Standard Identification Numbers (ASINs)</em> are unique blocks of 10 letters and/or numbers that identify items.<br>You can find the ASIN on the item's product information page at Amazon.")
        . '<br>'
        . $this->t('<em>European Article Number (EAN)</em> using the thirteen-digit EAN-13 standard.'),
    ];

    $form['execute']['actions'] = ['#type' => 'actions'];
    $form['execute']['actions']['op'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send request'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $code = $form_state->getValue('code');
    $result = $this->fetchProductData($code);
    if (!empty($result['response'])) {
      $_SESSION['chip_api.debug.response'] = $result['response'];
    }
    if (!empty($result['info'])) {
      foreach ($result['info'] as $info) {
        $this->messenger()->addStatus($info);
      }
    }
    if (!empty($result['errors'])) {
      foreach ($result['errors'] as $error) {
        $this->messenger()->addWarning($error);
      }
    }
  }

  /**
   * Fetches product data from Chip.
   *
   * @param string $code
   *   ASIN (10 letters and/or numbers) or GTIN-13 (EAN/UCC-13, 13 digits).
   *
   * @return array
   *   Array with keys: errors => [], info => [] and response => [].
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function fetchProductData($code) {
    $result = [
      'info' => [],
      'errors' => [],
      'response' => NULL,
    ];
    try {
      $result['response'] = $this->getChipApi()->getProduct($code);
    }
    catch (\Exception $e) {
      $this->getChipApi()->logException($e);
      $result['errors'][] = $e->getMessage();
    }
    if (!empty($result['response'])) {
      if (isset($result['response']['attributes'])) {
        $product_attributes = $result['response']['attributes'];
        // Multiple ASIN values per product.
        $result['info'][] = 'ASIN: ' . implode(', ', $product_attributes['asin']);
        $result['info'][] = 'EAN: ' . implode(', ', $product_attributes['gtins']);
        $result['info'][] = 'Title: ' . $product_attributes['fullName'];
        $prices = [];
        foreach ($result['response']['offers'] as $offer) {
          $prices[] = $offer['price'] . ' ' . $offer['currency'];
        }
        $label = count($result['response']['offers']) > 3
          ? 'The cheapest 3 offers plus Amazon: '
          : 'The cheapest 3 offers: ';
        $result['info'][] = $label . implode(', ', $prices);
      }
      if (isset($result['response']['offers'])) {
        $result['info'][] = 'Offer IDs: ' . implode(', ', array_keys(($result['response']['offers'])));
      }
      $result['info'][] = 'Detail Page URL: https://www.bestcheck.de/' . $result['response']['id'];
    }

    return $result;
  }

}
