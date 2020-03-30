<?php

namespace Drupal\chip_api;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelTrait;
use GuzzleHttp\Client;

/**
 * Service wrapper for Chip Api SDK.
 */
class ChipApi {

  use LoggerChannelTrait;

  /**
   * API product URL in sprintf() format (/bc/{resourceType} filtered by ASIN).
   */
  const API_BASE_URI = 'https://bc-api.bestcheck.de';

  /**
   * API version.
   */
  const API_VERSION = '1';

  /**
   * The settings keys in `chip_api.settings`.
   *
   * These can be overridden by environment variables.
   *
   * @see getSetting()
   */
  const SETTINGS_BA_USERNAME = 'ba_username';
  const SETTINGS_BA_PASSWORD = 'ba_password';

  /**
   * The server environment variables for specifying api credentials.
   *
   * Setting an environment variable with that name will override the
   * corresponding setting in `chip_api.settings`.
   *
   * @see getSetting()
   */
  const ENV_USERNAME  = 'CHIP_API_BA_USERNAME';
  const ENV_PASSWORD  = 'CHIP_API_BA_PASSWORD';

  /**
   * The GuzzleHttp client.
   *
   * @var Client
   */
  private $client;

  /**
   * Constructor.
   *
   * @throws \Exception
   */
  public function __construct() {
    // Initialize the GuzzleHttp client.
    $username = self::getUsername();
    $password = self::getPassword();
    if (empty($username) || empty($password)) {
      throw new \Exception('HTTP authentication must be configured');
    }
    $this->client = new Client([
      'Accept' => 'application/json',
      'auth' => [$username, $password],
      'base_uri' => self::API_BASE_URI,
    ]);
  }

  /**
   * Loads and decodes a BestCheck API call.
   *
   * @param string $url
   *   The relative URL.
   * @param array $queryParameters
   *   (optional) The query parameters.
   *
   * @return array
   *   The decoded JSON response.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Exception
   */
  private function request(string $url, array $queryParameters = array()) {
    $options = !empty($queryParameters)
      ? ['query' => $queryParameters]
      : [];
    $response = $this->client->request('GET', $url, $options);
    $response = Json::decode((string) $response->getBody());
    if (isset($response['errors'])) {
      $errors = '';
      foreach ($response['errors'] as $error) {
        $errors .= $error['title'] . ': ' . $error['detail'] . "\n";
      }
      throw new \Exception($errors);
    }

    return $response;
  }

  /**
   * Gets the Chip API product information.
   *
   * @param string $asin
   *   ASIN number.
   *
   * @return array().
   *   The product information.
   *
   * @throws \Exception
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getProduct(string $asin) {
    // Get product info.
    // /bc/product?filter[asin.in]=...
    $queryParameters = [
      'filter' => [
        'asin.in' => strtolower($asin),
      ],
      'fields' => [
        'product' => 'name,fullName,asin',
      ],
    ];
    $response = $this->request('/bc/product', $queryParameters);
    if (!isset($response['meta'], $response['data'][0]) || $response['meta']['total'] != 1) {
      throw new \Exception('No product identified for ASIN: ' . $asin);
    }
    $productInfo = $response['data'][0];
    unset($response);
    // Get prices / offers (from non-blacklisted merchants) because simply
    // filtering the offers by ASIN in one step doesn't work :(
    // The cheapest 3 offers, one per provider. Get the first 10 bcs a provider
    // can be present more than once. Also fetch merchant info.
    // /bc/apps/v1/cheapest_offers?filter[product.id.in]=...&offerCount=10
    $queryParameters = [
      'filter' => [
        'product.id.in' => $productInfo['id'],
      ],
      'offerCount' => 10,
      'fields' => [
        'offer' => 'description,price,currency,deeplink,merchant',
        'merchant' => 'name,url,active',
      ],
    ];
    $url = sprintf('/bc/apps/v%s/cheapest_offers', self::API_VERSION);
    $response = $this->request($url, $queryParameters);
    if (!isset($response['meta'], $response['data']) || empty($response['data'])) {
      throw new \Exception('No offers identified for ASIN: ' . $asin);
    }
    // Get cheapest 3 (but only one per merchant) and eventually Amazon.
    $fetchAmazonPrice = TRUE;
    $merchants = [];
    foreach ($response['included'] as $included) {
      if ($included['type'] == 'merchant') {
        $merchants[$included['id']] = $included['attributes'];
        if ($included['attributes']['name'] == 'Amazon') {
          $fetchAmazonPrice = FALSE;
        }
      }
    }
    $addAmazonPrice = !$fetchAmazonPrice;
    $countNonAmazonPrices = 0;
    foreach ($response['data'] as $offer) {
      $merchantId = $offer['relationships']['merchant']['data'][0]['id'];
      if (isset($productInfo['merchants'][$merchantId])) {
        // Skip prices after the first for the same merchant.
        continue;
      }
      $isFirstAmazonPrice = $addAmazonPrice && $merchants[$merchantId]['name'] == 'Amazon';
      if ($isFirstAmazonPrice || $countNonAmazonPrices < 3) {
        $productInfo['offers'][$offer['id']] = $offer['attributes'];
        $productInfo['offers'][$offer['id']]['merchant'] = $merchantId;
        $productInfo['merchants'][$merchantId] = $merchants[$merchantId];
        if ($isFirstAmazonPrice) {
          $addAmazonPrice = FALSE;
        }
        else {
          $countNonAmazonPrices++;
        }
      }
      if ($countNonAmazonPrices == 3 && !$addAmazonPrice) {
        break;
      }
    }
    if ($fetchAmazonPrice) {
      // Fetch and add the cheapest offer from Amazon (if found).
      $queryParameters['filter']['merchant.name.in'] = 'Amazon';
      $queryParameters['offerCount'] = 1;
      $response = $this->request($url, $queryParameters);
      $offer = $response['data'][0];
      $productInfo['offers'][$offer['id']] = $offer['attributes'];
      $productInfo['offers'][$offer['id']]['merchant'] = $offer['relationships']['merchant']['data'][0]['id'];
      foreach ($response['included'] as $included) {
        if ($included['type'] == 'merchant') {
          $productInfo['merchants'][$included['id']] = $included['attributes'];
        }
      }
    }

    return $productInfo;
  }

  /**
   * Gets the logger.
   *
   * @return \Psr\Log\LoggerInterface
   */
  public function logger() {
    return $this->getLogger('chip_api');
  }

  /**
   * Log exception.
   *
   * @param \Exception $e
   *   Exception caught when using the api.
   */
  public function logException(\Exception $e) {
    $this->logger()->error($e->getMessage());
  }

  /**
   * Returns the HTTP Basic authentication username needed for the api.
   *
   * @return string|bool
   */
  public static function getUsername() {
    return static::getSetting(static::SETTINGS_BA_USERNAME);
  }

  /**
   * Returns the HTTP Basic authentication password needed for the api.
   *
   * @return string|bool
   */
  public static function getPassword() {
    return static::getSetting(static::SETTINGS_BA_PASSWORD);
  }

  /**
   * Gets the environment variable name for a settings key.
   *
   * @param string $settings_key
   *   Any of self::SETTINGS_*.
   *
   * @return bool|string
   *   Any of self::ENV_* or FALSE if not found.
   */
  public static function getEnvVariable($settings_key) {
    $env_map = [
      self::SETTINGS_BA_USERNAME  => self::ENV_USERNAME,
      self::SETTINGS_BA_PASSWORD  => self::ENV_PASSWORD,
    ];

    return !empty($env_map[$settings_key]) ? $env_map[$settings_key] : FALSE;
  }

  /**
   * Gets the settings value from either the environment or config.
   *
   * @param string $settings_key
   *   Any of self::SETTINGS_*.
   *
   * @return bool|string
   *   The settings value or FALSE if not found.
   */
  public static function getSetting($settings_key) {
    if ($env_var = static::getEnvVariable($settings_key)) {
      if ($value = getenv($env_var)) {
        return $value;
      }
    }
    if ($value = \Drupal::config('chip_api.settings')->get($settings_key)) {
      return $value;
    }

    return FALSE;
  }

  /**
   * Gets all available settings keys.
   *
   * @return array
   *
   * @throws \ReflectionException
   */
  public static function getAvailableSettingsKeys() {
    $settings_keys = [];
    $reflect = new \ReflectionClass(static::class);
    foreach ($reflect->getConstants() as $key => $value) {
      if (strpos($key, 'SETTINGS_') === 0) {
        $settings_keys[$key] = $value;
      }
    }

    return $settings_keys;
  }

  /**
   * Checks whether the specific setting is set via environment variable.
   *
   * @param string $settings_key
   *   Any of self::SETTINGS_*.
   *
   * @return bool
   */
  public static function isSetInEnv($settings_key) {
    $env_var = static::getEnvVariable($settings_key);
    $value = getenv($env_var);
    return !empty($value);
  }

}

