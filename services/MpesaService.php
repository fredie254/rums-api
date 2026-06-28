<?php
/**
 * RUMS - M-Pesa Daraja API Service
 * Supports: STK Push (Lipa Na M-Pesa), C2B Callback
 *
 * Usage in the API: always pass the full $config array to the constructor
 * (read settings from DB first) so get_setting() is never invoked.
 */

class MpesaService
{
    private string $consumer_key;
    private string $consumer_secret;
    private string $shortcode;
    private string $passkey;
    private string $env;
    private string $callback_url;

    private const SANDBOX_BASE = 'https://sandbox.safaricom.co.ke';
    private const LIVE_BASE    = 'https://api.safaricom.co.ke';

    public function __construct(array $config = [])
    {
        $this->consumer_key    = $config['consumer_key']    ?? '';
        $this->consumer_secret = $config['consumer_secret'] ?? '';
        $this->shortcode       = $config['shortcode']       ?? '';
        $this->passkey         = $config['passkey']         ?? '';
        $this->env             = $config['env']             ?? 'sandbox';
        $this->callback_url    = $config['callback_url']    ?? '';
    }

    private function baseUrl(): string
    {
        return $this->env === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
    }

    public function getAccessToken(): string
    {
        // Cache the token in APCu — Safaricom tokens are valid for 3600 s.
        // Use a 55-minute TTL so we refresh before expiry with margin to spare.
        $cacheKey = 'rums_mpesa_tok_' . substr(md5($this->consumer_key . $this->env), 0, 16);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit) return $cached;
        }

        $url         = $this->baseUrl() . '/oauth/v1/generate?grant_type=client_credentials';
        $credentials = base64_encode($this->consumer_key . ':' . $this->consumer_secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException('M-Pesa token request failed: ' . $err);

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Failed to get access token: ' . $response);
        }

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $data['access_token'], 3300); // 55 minutes
        }

        return $data['access_token'];
    }

    public function stkPush(string $phone, float $amount, string $account = 'RENT', string $desc = 'Rent Payment'): array
    {
        $token     = $this->getAccessToken();
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int)ceil($amount),
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callback_url,
            'AccountReference'  => substr($account, 0, 12),
            'TransactionDesc'   => substr($desc, 0, 13),
        ];

        return $this->post('/mpesa/stkpush/v1/processrequest', $payload, $token);
    }

    public function stkQuery(string $checkout_request_id): array
    {
        $token     = $this->getAccessToken();
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);

        return $this->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkout_request_id,
        ], $token);
    }

    private function post(string $endpoint, array $payload, string $token): array
    {
        $url = $this->baseUrl() . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException('M-Pesa API error: ' . $err);
        return json_decode($response, true) ?? [];
    }

    public static function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0'))  return '254' . substr($phone, 1);
        if (str_starts_with($phone, '+'))  return ltrim($phone, '+');
        return $phone;
    }
}
