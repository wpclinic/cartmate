<?php
if (!defined('ABSPATH')) exit;

class CartMate_Phone {

  public static function dial_codes() {
    $file = dirname(__FILE__) . '/data/cartmate-dial-codes.php';
    return file_exists($file) ? (array) require $file : [];
  }

  public static function to_e164($raw_phone, $country_code) {
    $raw_phone = trim((string)$raw_phone);
    if ($raw_phone === '') return '';

    // If already +E164
    if (strpos($raw_phone, '+') === 0) {
      return '+' . preg_replace('/\D+/', '', $raw_phone);
    }

    $digits = preg_replace('/\D+/', '', $raw_phone);
    if ($digits === '') return '';

    // 00 international prefix
    if (strpos($digits, '00') === 0) {
      return '+' . substr($digits, 2);
    }

    $codes = self::dial_codes();
    $dial  = isset($codes[$country_code]) ? $codes[$country_code] : '';

    if ($dial === '') {
      // No dial code available for this country in our map.
      return '';
    }

    // Remove one leading trunk 0 (common in AU/UK/NZ/etc)
    if (strpos($digits, '0') === 0) {
      $digits = ltrim($digits, '0');
    }

    return '+' . $dial . $digits;
  }
}
