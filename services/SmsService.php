<?php
/**
 * RUMS — Africa's Talking SMS Service
 *
 * Sends SMS via the Africa's Talking Messaging API.
 * Config is read from the settings table (sms_api_key, sms_username, sms_sender).
 *
 * Africa's Talking API docs:
 *   https://developers.africastalking.com/docs/sms/sending
 */
class SmsService
{
    private string $apiKey;
    private string $username;
    private string $senderId;
    private bool   $sandbox;

    private const LIVE_URL    = 'https://api.africastalking.com/version1/messaging';
    private const SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

    public function __construct(array $config = [])
    {
        $this->apiKey   = $config['api_key']   ?? '';
        $this->username = $config['username']   ?? 'sandbox';
        $this->senderId = $config['sender_id']  ?? '';
        $this->sandbox  = ($this->username === 'sandbox');
    }

    /**
     * Send a single SMS message.
     *
     * @param  string|array $to   Phone number(s) in E.164 or local format
     * @param  string       $msg  Message body (max 160 chars for single SMS)
     * @return array  ['success'=>bool, 'message_id'=>string|null, 'cost'=>string|null, 'error'=>string|null]
     */
    public function send(string|array $to, string $msg): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'SMS not configured. Add api_key in settings.'];
        }

        // Normalise recipient list
        $numbers = is_array($to) ? $to : [$to];
        $numbers = array_map([$this, 'formatPhone'], array_filter($numbers));
        if (empty($numbers)) {
            return ['success' => false, 'error' => 'No valid recipients.'];
        }

        $payload = [
            'username' => $this->username,
            'to'       => implode(',', $numbers),
            'message'  => $msg,
        ];
        if ($this->senderId) {
            $payload['from'] = $this->senderId;
        }

        $url = $this->sandbox ? self::SANDBOX_URL : self::LIVE_URL;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_HTTPHEADER     => [
                'apiKey: ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'cURL error: ' . $err];
        }

        $data = json_decode($response, true);
        $data = $data['SMSMessageData'] ?? null;

        if (!$data) {
            return ['success' => false, 'error' => 'Unexpected API response: ' . $response];
        }

        $recipients = $data['Recipients'] ?? [];
        $first      = $recipients[0] ?? [];
        $statusCode = (int)($first['statusCode'] ?? 0);
        $success    = in_array($statusCode, [100, 101]); // 101=sent, 100=queued

        return [
            'success'    => $success,
            'message_id' => $first['messageId'] ?? null,
            'cost'       => $first['cost']       ?? null,
            'status'     => $first['status']     ?? null,
            'error'      => $success ? null : ($first['status'] ?? $data['Message'] ?? 'Unknown error'),
        ];
    }

    /**
     * Send to multiple recipients (bulk).
     * Returns ['sent'=>N, 'failed'=>N, 'results'=>[...]].
     */
    public function sendBulk(array $recipients, string $msg): array
    {
        $sent   = 0;
        $failed = 0;
        $results = [];

        // Africa's Talking supports up to 1000 numbers per call
        foreach (array_chunk($recipients, 100) as $chunk) {
            $res = $this->send($chunk, $msg);
            if ($res['success']) {
                $sent += count($chunk);
            } else {
                $failed += count($chunk);
            }
            $results[] = $res;
        }

        return compact('sent', 'failed', 'results');
    }

    public static function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0'))   return '+254' . substr($phone, 1);
        if (str_starts_with($phone, '254')) return '+' . $phone;
        if (str_starts_with($phone, '+'))   return $phone;
        return '+' . $phone;
    }
}
