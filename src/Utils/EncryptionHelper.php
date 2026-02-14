<?php
// src/Utils/EncryptionHelper.php

namespace App\Utils;

class EncryptionHelper
{
    private static $cipher = 'AES-256-CBC';
    private static $key = 'mon_sys_encryption_key_32_chars!'; // 32-byte key
    private static $ivLength = 16;

    public static function encrypt($plaintext)
    {
        $iv = random_bytes(self::$ivLength);
        $encrypted = openssl_encrypt($plaintext, self::$cipher, self::$key, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            return false;
        }
        
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt($ciphertext)
    {
        $ciphertext = base64_decode($ciphertext);
        
        if ($ciphertext === false) {
            return false;
        }
        
        $iv = substr($ciphertext, 0, self::$ivLength);
        $encrypted = substr($ciphertext, self::$ivLength);
        
        return openssl_decrypt($encrypted, self::$cipher, self::$key, OPENSSL_RAW_DATA, $iv);
    }
}
