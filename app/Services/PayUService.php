<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;

/**
 * PayU (India) hosted checkout.
 *
 * Request hash : sha512(key|txnid|amount|productinfo|firstname|email|udf1|...|udf5||||||salt)
 * Response hash: sha512(salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
 *
 * A payment is only ever marked successful after the response hash matches AND
 * (when reachable) PayU's verify_payment API confirms the status.
 */
final class PayUService
{
    public function config(): array
    {
        $mode = Settings::string('payu_mode', 'test') === 'live' ? 'live' : 'test';
        $baseUrl = Settings::string('payu_base_url');

        if ($baseUrl === '') {
            $baseUrl = $mode === 'live' ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment';
        }

        return [
            'mode' => $mode,
            'merchant_key' => Settings::string('payu_merchant_key'),
            'merchant_salt' => Settings::string('payu_merchant_salt'),
            'base_url' => $baseUrl,
            'verify_url' => $mode === 'live'
                ? 'https://info.payu.in/merchant/postservice.php?form=2'
                : 'https://test.payu.in/merchant/postservice.php?form=2',
        ];
    }

    public function isConfigured(): bool
    {
        $config = $this->config();

        return $config['merchant_key'] !== '' && $config['merchant_salt'] !== '';
    }

    public function mode(): string
    {
        return $this->config()['mode'];
    }

    public function newTransactionId(): string
    {
        return 'DP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Build everything the checkout form needs.
     *
     * @return array{action:string, fields:array<string, string>}
     */
    public function buildRequest(array $params): array
    {
        $config = $this->config();

        $fields = [
            'key' => $config['merchant_key'],
            'txnid' => (string) $params['txnid'],
            'amount' => number_format((float) $params['amount'], 2, '.', ''),
            'productinfo' => $this->clean((string) $params['productinfo'], 100),
            'firstname' => $this->clean((string) $params['firstname'], 60),
            'email' => (string) $params['email'],
            'phone' => $this->clean((string) ($params['phone'] ?? '9999999999'), 15),
            'surl' => (string) $params['surl'],
            'furl' => (string) $params['furl'],
            'udf1' => (string) ($params['udf1'] ?? ''),
            'udf2' => (string) ($params['udf2'] ?? ''),
            'udf3' => '',
            'udf4' => '',
            'udf5' => '',
            'service_provider' => 'payu_paisa',
        ];

        $fields['hash'] = $this->requestHash($fields, $config['merchant_salt']);

        return ['action' => $config['base_url'], 'fields' => $fields];
    }

    public function requestHash(array $f, string $salt): string
    {
        $sequence = implode('|', [
            $f['key'],
            $f['txnid'],
            $f['amount'],
            $f['productinfo'],
            $f['firstname'],
            $f['email'],
            $f['udf1'] ?? '',
            $f['udf2'] ?? '',
            $f['udf3'] ?? '',
            $f['udf4'] ?? '',
            $f['udf5'] ?? '',
            '', '', '', '', '',
            $salt,
        ]);

        return strtolower(hash('sha512', $sequence));
    }

    /**
     * Verify the hash PayU posts back to the success/failure URL.
     */
    public function verifyResponseHash(array $post): bool
    {
        $config = $this->config();

        if ($config['merchant_salt'] === '') {
            return false;
        }

        $received = strtolower((string) ($post['hash'] ?? ''));

        if ($received === '') {
            return false;
        }

        $additionalCharges = (string) ($post['additionalCharges'] ?? '');

        $sequence = implode('|', [
            $config['merchant_salt'],
            (string) ($post['status'] ?? ''),
            '', '', '', '', '',
            (string) ($post['udf5'] ?? ''),
            (string) ($post['udf4'] ?? ''),
            (string) ($post['udf3'] ?? ''),
            (string) ($post['udf2'] ?? ''),
            (string) ($post['udf1'] ?? ''),
            (string) ($post['email'] ?? ''),
            (string) ($post['firstname'] ?? ''),
            (string) ($post['productinfo'] ?? ''),
            (string) ($post['amount'] ?? ''),
            (string) ($post['txnid'] ?? ''),
            (string) ($post['key'] ?? ''),
        ]);

        if ($additionalCharges !== '') {
            $sequence = $additionalCharges . '|' . $sequence;
        }

        return hash_equals(strtolower(hash('sha512', $sequence)), $received);
    }

    /**
     * Server-to-server confirmation through PayU's verify_payment API.
     *
     * @return array{success:bool, status:string, message:string, raw:array}
     */
    public function verifyPayment(string $txnid): array
    {
        $config = $this->config();

        if (!$this->isConfigured()) {
            return ['success' => false, 'status' => 'unknown', 'message' => 'PayU is not configured.', 'raw' => []];
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 'unknown', 'message' => 'PHP cURL is not available.', 'raw' => []];
        }

        $command = 'verify_payment';
        $hash = strtolower(hash('sha512', $config['merchant_key'] . '|' . $command . '|' . $txnid . '|' . $config['merchant_salt']));

        $ch = curl_init($config['verify_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'key' => $config['merchant_key'],
                'command' => $command,
                'var1' => $txnid,
                'hash' => $hash,
            ]),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            Logger::warning('PayU verify_payment failed: ' . $error);

            return ['success' => false, 'status' => 'unknown', 'message' => 'Could not reach PayU: ' . $error, 'raw' => []];
        }

        $body = json_decode((string) $raw, true);

        if (!is_array($body)) {
            return ['success' => false, 'status' => 'unknown', 'message' => 'Unexpected response from PayU.', 'raw' => []];
        }

        $transaction = is_array($body['transaction_details'] ?? null)
            ? ($body['transaction_details'][$txnid] ?? [])
            : [];

        $status = strtolower((string) ($transaction['status'] ?? ''));

        return [
            'success' => $status === 'success',
            'status' => $status !== '' ? $status : 'unknown',
            'message' => (string) ($body['msg'] ?? ''),
            'raw' => $body,
        ];
    }

    /**
     * Decide whether a callback really represents a paid transaction.
     *
     * @return array{paid:bool, reason:string, hash_valid:bool, api_status:string}
     */
    public function confirm(array $post): array
    {
        $hashValid = $this->verifyResponseHash($post);
        $status = strtolower((string) ($post['status'] ?? ''));
        $txnid = (string) ($post['txnid'] ?? '');

        if (!$hashValid) {
            return ['paid' => false, 'reason' => 'The payment response signature could not be verified.', 'hash_valid' => false, 'api_status' => ''];
        }

        if ($status !== 'success') {
            return [
                'paid' => false,
                'reason' => (string) ($post['error_Message'] ?? $post['field9'] ?? 'The payment was not completed.'),
                'hash_valid' => true,
                'api_status' => $status,
            ];
        }

        $verification = $this->verifyPayment($txnid);

        // If PayU's API is unreachable we still trust a valid hash, but we record why.
        if ($verification['status'] === 'unknown') {
            return [
                'paid' => true,
                'reason' => 'Hash verified (verify_payment API unreachable: ' . $verification['message'] . ')',
                'hash_valid' => true,
                'api_status' => 'unknown',
            ];
        }

        if (!$verification['success']) {
            return [
                'paid' => false,
                'reason' => 'PayU reported the transaction as "' . $verification['status'] . '".',
                'hash_valid' => true,
                'api_status' => $verification['status'],
            ];
        }

        return ['paid' => true, 'reason' => 'Verified with PayU.', 'hash_valid' => true, 'api_status' => 'success'];
    }

    private function clean(string $value, int $length): string
    {
        $value = preg_replace('/[^A-Za-z0-9 .\-_@]/', '', $value) ?? '';

        return mb_substr(trim($value), 0, $length);
    }
}
