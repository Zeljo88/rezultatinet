<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OneSignalService
{
    private string $appId;
    private string $restApiKey;
    private string $apiUrl = 'https://onesignal.com/api/v1/notifications';

    public function __construct()
    {
        $this->appId      = config('services.onesignal.app_id', '');
        $this->restApiKey = config('services.onesignal.rest_api_key', '');
    }

    /**
     * Send a push notification to all subscribers.
     */
    public function sendToAll(string $title, string $message, string $url = '', array $data = []): bool
    {
        if (empty($this->appId) || empty($this->restApiKey)) {
            Log::warning('OneSignal: missing app_id or rest_api_key in config');
            return false;
        }

        $payload = [
            'app_id'            => $this->appId,
            'included_segments' => ['All'],
            'headings'          => ['en' => $title],
            'contents'          => ['en' => $message],
        ];

        if (!empty($url)) {
            $payload['url'] = $url;
        }

        if (!empty($data)) {
            $payload['data'] = $data;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->restApiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->apiUrl, $payload);

            if ($response->successful()) {
                Log::info('OneSignal push sent: ' . $title . ' | ' . $message);
                return true;
            }

            Log::warning('OneSignal push failed: ' . $response->status() . ' ' . $response->body());
            return false;
        } catch (\Throwable $e) {
            Log::warning('OneSignal push exception: ' . $e->getMessage());
            return false;
        }
    }
}
