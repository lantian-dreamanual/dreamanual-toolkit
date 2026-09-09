<?php
/**
 * 加密工具类 —— AES-256-CBC 加解密
 *
 * 优化 2：从 AI_Client 抽取独立工具类，供 AI_Client 与 site-enhance SMTP 密码共用
 *
 * @package Dreamanual_Toolkit
 */

namespace DREA;

defined( 'ABSPATH' ) || exit;

class Crypto {

    /**
     * 加密明文
     *
     * @param string $plain_text 明文。
     * @return string 加密后的 base64 字符串。
     */
    public static function encrypt( string $plain_text ): string {
        $key       = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
        $iv_len    = openssl_cipher_iv_length( 'aes-256-cbc' );
        $iv        = openssl_random_pseudo_bytes( $iv_len );
        $encrypted = openssl_encrypt( $plain_text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * 解密密文
     *
     * @param string $cipher_text 加密的 base64 字符串。
     * @return string 明文，失败返回空字符串。
     */
    public static function decrypt( string $cipher_text ): string {
        $key  = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
        $data = base64_decode( $cipher_text, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if ( false === $data ) {
            return '';
        }
        $iv_len    = openssl_cipher_iv_length( 'aes-256-cbc' );
        $iv        = substr( $data, 0, $iv_len );
        $encrypted = substr( $data, $iv_len );
        $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return false === $decrypted ? '' : $decrypted;
    }
}

/**
 * 错误翻译工具类 —— 网络错误友好提示
 *
 * 优化 3：统一 SMTP / 搜索推送 / AI 请求中的网络连接类错误翻译逻辑
 *
 * @package Dreamanual_Toolkit
 */
class Error_Translator {

    /**
     * 将网络错误翻译为用户友好的中文提示
     *
     * @param string $error      原始错误信息。
     * @param string $context    错误上下文名称（如 "SMTP"、"Baidu push"），用于拼接提示。
     * @return string 友好的中文错误提示。
     */
    public static function translate_network_error( string $error, string $context = '' ): string {
        $lower = strtolower( $error );

        if ( false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'timeout' ) ) {
            /* translators: %s: error context (e.g. "SMTP", "Baidu push") */
            $msg = __( '%s: request timed out, please retry later.', 'dreamanual-toolkit' );
            return $context ? sprintf( $msg, $context ) : __( 'Request timed out, please retry later.', 'dreamanual-toolkit' );
        }

        if ( false !== strpos( $lower, 'could not resolve host' )
            || false !== strpos( $lower, 'connection refused' )
            || false !== strpos( $lower, 'network is unreachable' )
            || false !== strpos( $lower, 'connect() failed' )
            || false !== strpos( $lower, 'connection timed out' )
        ) {
            /* translators: %s: error context */
            $msg = __( '%s: cannot connect to server, please check network connection.', 'dreamanual-toolkit' );
            return $context ? sprintf( $msg, $context ) : __( 'Cannot connect to server, please check network connection.', 'dreamanual-toolkit' );
        }

        if ( false !== strpos( $lower, 'ssl' ) || false !== strpos( $lower, 'certificate' ) ) {
            /* translators: %s: error context */
            $msg = __( '%s: SSL certificate verification failed.', 'dreamanual-toolkit' );
            return $context ? sprintf( $msg, $context ) : __( 'SSL certificate verification failed.', 'dreamanual-toolkit' );
        }

        // 兜底
        /* translators: %s: error context */
        $msg = __( '%s failed, please check network connection or retry later.', 'dreamanual-toolkit' );
        return $context ? sprintf( $msg, $context ) : __( 'Request failed, please check network connection or retry later.', 'dreamanual-toolkit' );
    }
}
