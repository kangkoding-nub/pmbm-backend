<?php

namespace App\Services;

use App\Models\User;
use App\Models\Whatsapp;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $url;
    protected string $user;
    protected string $pass;
    protected string $deviceId;

    public function __construct()
    {
        $this->url = config('whatsapp.url');
        $this->user = config('whatsapp.user');
        $this->pass = config('whatsapp.pass');
        $this->deviceId = config('whatsapp.device_id', '');
    }

    /**
     * Standardize phone number formatting.
     *
     * @param string $phone
     * @return string
     */
    private function formatPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleaned, '0')) {
            $cleaned = '62' . substr($cleaned, 1);
        }
        if (!str_ends_with($cleaned, '@s.whatsapp.net')) {
            $cleaned .= '@s.whatsapp.net';
        }
        return $cleaned;
    }

    private function getHeaders(string $device): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($device != "") {
            $headers['X-Device-Id'] = $device;
        }

        return $headers;
    }

    private function getMultipartHeaders(string $device): array
    {
        $headers = [];

        if (!empty($this->deviceId)) {
            $headers['X-Device-Id'] = $device;
        }

        return $headers;
    }

    private function getDeviceId(string $phone): void
    {
        $defaultDevice = config('whatsapp.device_id', 'whatsapp-yayasan-1') ?: 'whatsapp-yayasan-1';

        $user = User::where('phone', $phone)->first();
        if (!$user || $user->institutionId === null) {
            $this->deviceId = $defaultDevice;
            return;
        }

        $whatsapp = Whatsapp::where('institutionId', $user->institutionId)->first();
        $this->deviceId = $whatsapp?->device ?: $defaultDevice;
    }

    public function deviceAdd(string $device)
    {
        try {
            $response = Http::withHeaders($this->getHeaders($device))
                ->withBasicAuth($this->user, $this->pass)
                ->post("$this->url/devices", [
                    'device_id' => $device,
                ]);
            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('WhatsApp Service Error: ' . $response->body());
                return $response->body();
            }

        } catch (Exception $e) {
            Log::error('WhatsApp Service Exception: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    public function deviceInfo(string $device)
    {
        try {
            $response = Http::withHeaders($this->getHeaders($device))
                ->withBasicAuth($this->user, $this->pass)
                ->get("$this->url/devices/$device");
            if ($response->successful()) {
                return $response->json();
            } else {
                $this->deviceAdd($device);
                return $response->body();
            }

        } catch (Exception $e) {
            Log::error('WhatsApp Service Error: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    public function deviceRemove(string $device)
    {
        try {
            $response = Http::withHeaders($this->getHeaders($device))
                ->withBasicAuth($this->user, $this->pass)
                ->delete("$this->url/devices/$device");
            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('WhatsApp Service Error: ' . $response->body());
                return $response->body();
            }

        } catch (Exception $e) {
            Log::error('WhatsApp Service Exception: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    public function deviceLogin(string $device)
    {
        try {
            $response = Http::withHeaders($this->getHeaders($device))
                ->withBasicAuth($this->user, $this->pass)
                ->get("$this->url/app/login");
            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('WhatsApp Service Error: ' . $response->body());
                return $response->body();
            }

        } catch (Exception $e) {
            Log::error('WhatsApp Service Exception: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    public function sendTyping(string $phone, string $action): void
    {
        try {
            $this->getDeviceId($phone);

            if (empty($this->deviceId)) {
                Log::warning("WhatsApp sendTyping skipped: no device ID resolved for phone [{$phone}]");
                return;
            }

            $response = Http::withHeaders($this->getHeaders($this->deviceId))
                ->withBasicAuth($this->user, $this->pass)
                ->post("$this->url/send/chat-presence", [
                    'phone' => $this->formatPhone($phone),
                    'action' => $action,
                ]);
            if ($response->successful()) {
                return;
            }
            Log::warning('WhatsApp sendTyping failed: ' . $response->body());
            return;
        } catch (Exception $e) {
            Log::error('WhatsApp Service Exception (sendTyping): ' . $e->getMessage());
            return;
        }
    }

    public function sendMessage(string $phone, string $message): ?array
    {
        try {
            $this->getDeviceId($phone);
            $response = Http::withHeaders($this->getHeaders($this->deviceId))
                ->withBasicAuth($this->user, $this->pass)
                ->post("$this->url/send/message", [
                    'phone' => $this->formatPhone($phone),
                    'message' => $message,
                    'is_forwarded' => false,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            $body = $response->json();
            Log::error('WhatsApp Service Error: ' . $response->body());
            return [
                'success' => false,
                'error' => $body['code'] ?? 'UNKNOWN_ERROR',
                'message' => $body['message'] ?? 'Unknown error'
            ];
        } catch (Exception $e) {
            Log::error('WhatsApp Service Exception: ' . $e->getMessage());
            return null;
        }
    }

    public function sendImage(string $phone, string $imageUrl, string $caption = ''): ?array
    {
        try {
            $this->getDeviceId($phone);
            $response = Http::withHeaders($this->getMultipartHeaders($this->deviceId))
                ->withBasicAuth($this->user, $this->pass)
                ->asMultipart()
                ->post("$this->url/send/image", [
                    ['name' => 'phone', 'contents' => $this->formatPhone($phone)],
                    ['name' => 'caption', 'contents' => $caption],
                    ['name' => 'image_url', 'contents' => $imageUrl],
                    ['name' => 'view_once', 'contents' => 'false'],
                    ['name' => 'compress', 'contents' => 'false'],
                    ['name' => 'is_forwarded', 'contents' => 'false'],
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            $body = $response->json();
            Log::error('WhatsApp Service (Image) Error: ' . $response->body());
            return [
                'success' => false,
                'error' => $body['code'] ?? 'UNKNOWN_ERROR',
                'message' => $body['message'] ?? 'Unknown error'
            ];
        } catch (Exception $e) {
            Log::error('WhatsApp Service (Image) Exception: ' . $e->getMessage());
            return null;
        }
    }

    public function sendFile(string $phone, string $filePath, string $caption = ''): ?array
    {
        try {
            // Defense against path traversal / arbitrary local file read.
            // Only files inside the application's storage directory may be
            // attached. Resolve to an absolute path and verify it falls
            // beneath storage_path() before reading.
            $resolved = realpath($filePath);
            $allowedBase = realpath(storage_path());
            if (
                $resolved === false
                || $allowedBase === false
                || !str_starts_with($resolved, $allowedBase . DIRECTORY_SEPARATOR)
                || !is_file($resolved)
            ) {
                Log::warning('WhatsApp sendFile rejected: file outside allowed directory', [
                    'requested' => $filePath,
                ]);
                return [
                    'success' => false,
                    'error' => 'INVALID_FILE_PATH',
                    'message' => 'File yang diminta tidak diizinkan untuk dikirim.',
                ];
            }

            $this->getDeviceId($phone);
            $response = Http::withHeaders($this->getMultipartHeaders($this->deviceId))
                ->withBasicAuth($this->user, $this->pass)
                ->asMultipart()
                ->attach('file', file_get_contents($resolved), basename($resolved))
                ->post("$this->url/send/file", [
                    ['name' => 'phone',        'contents' => $this->formatPhone($phone)],
                    ['name' => 'caption',      'contents' => $caption],
                    ['name' => 'is_forwarded', 'contents' => 'false'],
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            $body = $response->json();
            Log::error('WhatsApp Service (File) Error: ' . $response->body());
            return [
                'success' => false,
                'error' => $body['code'] ?? 'UNKNOWN_ERROR',
                'message' => $body['message'] ?? 'Unknown error'
            ];
        } catch (Exception $e) {
            Log::error('WhatsApp Service (File) Exception: ' . $e->getMessage());
            return null;
        }
    }
}
