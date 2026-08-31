<?php
/**
 * Bitrix24 REST API Helper (CRest compatible)
 */
require_once __DIR__ . '/config.php';

class CRest {
    protected static $datafile = __DIR__ . '/data/settings.json';

    public static function call($method, $params = []) {
        $arSettings = static::getSettings();

        // Update settings from incoming request parameters if provided by Bitrix24 iframe/AJAX
        $hasNewTokens = false;
        if (!empty($_REQUEST['AUTH_ID']) && (!isset($arSettings['AUTH_ID']) || $_REQUEST['AUTH_ID'] !== $arSettings['AUTH_ID'])) {
            $arSettings['AUTH_ID'] = $_REQUEST['AUTH_ID'];
            $hasNewTokens = true;
        }
        if (!empty($_REQUEST['REFRESH_ID'])) {
            $arSettings['REFRESH_ID'] = $_REQUEST['REFRESH_ID'];
            $hasNewTokens = true;
        }
        if (!empty($_REQUEST['DOMAIN'])) {
            $arSettings['DOMAIN'] = $_REQUEST['DOMAIN'];
            $hasNewTokens = true;
        }

        if ($hasNewTokens) {
            static::setSettings($arSettings);
        }

        if (empty($arSettings['DOMAIN']) || (empty($arSettings['AUTH_ID']) && empty($arSettings['WEBHOOK_URL']))) {
            return ['error' => 'NO_AUTH_SETTINGS', 'error_description' => 'Bitrix24 domain or AUTH_ID not configured.'];
        }

        if (!empty($arSettings['WEBHOOK_URL'])) {
            $url = rtrim($arSettings['WEBHOOK_URL'], '/') . '/' . $method . '.json';
            $queryData = http_build_query($params);
        } else {
            $url = 'https://' . $arSettings['DOMAIN'] . '/rest/' . $method . '.json';
            $params['auth'] = $arSettings['AUTH_ID'];
            $queryData = http_build_query($params);
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_URL => $url,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $queryData,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $response = json_decode($result, true);

        // Check if token expired and handle refresh
        if (isset($response['error']) && in_array($response['error'], ['expired_token', 'invalid_token'])) {
            if (static::refreshToken()) {
                // Token refreshed, retry API call once
                return static::call($method, $params);
            }
        }

        return $response ?: ['error' => 'HTTP_ERROR_' . $httpCode, 'raw' => $result];
    }

    public static function getSettings() {
        if (file_exists(static::$datafile)) {
            $content = file_get_contents(static::$datafile);
            return json_decode($content, true) ?: [];
        }
        return [];
    }

    public static function setSettings($arSettings) {
        $dir = dirname(static::$datafile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return @file_put_contents(static::$datafile, json_encode($arSettings, JSON_PRETTY_PRINT));
    }

    public static function refreshToken() {
        $arSettings = static::getSettings();
        if (empty($arSettings['REFRESH_ID']) || empty($arSettings['DOMAIN'])) {
            return false;
        }

        $url = 'https://oauth.bitrix.info/oauth/token/?' . http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => C_REST_CLIENT_ID,
            'client_secret' => C_REST_CLIENT_SECRET,
            'refresh_token' => $arSettings['REFRESH_ID'],
        ]);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $result = curl_exec($curl);
        curl_close($curl);

        $response = json_decode($result, true);
        if (!empty($response['access_token'])) {
            $arSettings['AUTH_ID'] = $response['access_token'];
            if (!empty($response['refresh_token'])) {
                $arSettings['REFRESH_ID'] = $response['refresh_token'];
            }
            static::setSettings($arSettings);
            return true;
        }

        return false;
    }
}
