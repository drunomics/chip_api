<?php

namespace Drupal\chip_api;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service wrapper for Chip Api SDK.
 */
class ChipApi {

  use LoggerChannelTrait;

  /**
   * API product URL in sprintf() format (/bc/{resourceType} filtered by ASIN).
   */
  const API_PRODUCT_URL = 'https://bc-api.bestcheck.de/bc/product?filter%%5Basin.in%%5D=%s';

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
   * Gets the Chip API.
   *
   * @param string $asin
   *  ASIN number.
   *
   * @return array().
   *   Array with keys: errors => [], response => json.
   */
  public function getProduct(string $asin) {
    $result = [
      'errors' => [],
      'response' => NULL,
    ];
    $username = self::getUsername();
    $password = self::getPassword();
    if (empty($username) || empty($password)) {
      $result['errors'][] = 'HTTP authentication must be configured';
      return $result;
    }

    $asin_lower = strtolower($asin);
    $url = sprintf(self::API_PRODUCT_URL, $asin_lower);

    $client = new Client();
    try {
      $response = $client->request('GET', $url, [
        'Accept' => 'application/json',
        'auth' => [$username, $password],
      ]);
      $response = Json::decode((string) $response->getBody());
      if (isset($response['errors'])) {
        foreach ($response['errors'] as $error) {
          $result['errors'][] = $error['title'] . ': ' . $error['detail'];
        }
      }
      elseif (isset($response['meta'], $response['data'][0]) && $response['meta']['total'] == 1) {
        $result['response'] = $response['data'][0];
      }
      else {
        $result['errors'][] = 'No product identified for ASIN: ' . $asin;
      }
    }
    catch (GuzzleException $e) {
      $result['errors'][] = $e->getMessage();
    }

    return $result;
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
