<?php
declare(strict_types=1);

namespace App\Services;

use Database;

final class AiRiskService
{
    public function predict(array $features): ?array
    {
        $endpoint = rtrim(getenv('SBMS_AI_SERVICE_URL') ?: 'http://127.0.0.1:8000', '/') . '/predict';
        $payload = json_encode($features, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return null;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 5,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            Database::logError('AI prediction request failed', [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'error' => $error,
            ]);
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
