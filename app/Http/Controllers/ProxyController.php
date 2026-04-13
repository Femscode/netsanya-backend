<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProxyController extends Controller
{
    public function send(Request $request)
    {
        $configInput = $request->input('config');

        if (is_string($configInput)) {
            $config = json_decode($configInput, true);
            if (! is_array($config)) {
                throw ValidationException::withMessages([
                    'config' => ['Config must be valid JSON.'],
                ]);
            }
        } else {
            $config = $request->all();
        }

        $data = validator($config, [
            'method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'url' => ['required', 'string', 'max:2048'],
            'headers' => ['nullable', 'array', 'max:100'],
            'headers.*' => ['nullable'],
            'query' => ['nullable', 'array', 'max:200'],
            'auth' => ['nullable', 'array'],
            'auth.type' => ['nullable', 'string', 'in:none,bearer,basic,api_key'],
            'auth.bearer' => ['nullable', 'string', 'max:2000'],
            'auth.basic.username' => ['nullable', 'string', 'max:500'],
            'auth.basic.password' => ['nullable', 'string', 'max:2000'],
            'auth.api_key.in' => ['nullable', 'string', 'in:header,query'],
            'auth.api_key.key' => ['nullable', 'string', 'max:200'],
            'auth.api_key.value' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'array'],
            'body.type' => ['nullable', 'string', 'in:none,json,raw,form-data,x-www-form-urlencoded,binary'],
            'body.json' => ['nullable', 'array'],
            'body.raw' => ['nullable', 'string', 'max:2000000'],
            'body.raw_content_type' => ['nullable', 'string', 'max:200'],
            'body.form' => ['nullable', 'array', 'max:200'],
            'body.form.*.key' => ['required_with:body.form', 'string', 'max:200'],
            'body.form.*.type' => ['required_with:body.form', 'string', 'in:text,file'],
            'body.form.*.value' => ['nullable', 'string', 'max:2000000'],
            'body.form.*.file_key' => ['nullable', 'string', 'max:200'],
            'body.binary_file_key' => ['nullable', 'string', 'max:200'],
            'body.binary_content_type' => ['nullable', 'string', 'max:200'],
        ])->validate();

        $url = $data['url'];

        if (! $this->isSafeUrl($url)) {
            throw ValidationException::withMessages([
                'url' => ['The provided URL is not allowed.'],
            ]);
        }

        $method = strtoupper($data['method']);

        $headers = $this->sanitizeOutboundHeaders($data['headers'] ?? []);

        [$headers, $query] = $this->applyAuth(
            $headers,
            $data['query'] ?? [],
            $data['auth'] ?? null
        );

        $maxBodyBytes = (int) env('PROXY_MAX_BODY_BYTES', 100_000);
        $bodyType = strtolower($data['body']['type'] ?? 'json');
        $jsonBody = null;
        $rawBody = null;
        $rawContentType = $data['body']['raw_content_type'] ?? null;
        $multipart = null;
        $formParams = null;
        $binary = null;
        $binaryContentType = $data['body']['binary_content_type'] ?? null;

        if ($bodyType === 'json') {
            $jsonBody = $data['body']['json'] ?? null;
            if ($jsonBody !== null) {
                $encoded = json_encode($jsonBody);

                if ($encoded === false) {
                    throw ValidationException::withMessages([
                        'body.json' => ['Body must be valid JSON.'],
                    ]);
                }

                if (strlen($encoded) > $maxBodyBytes) {
                    throw ValidationException::withMessages([
                        'body.json' => ['Body exceeds maximum allowed size.'],
                    ]);
                }
            }
        } elseif ($bodyType === 'raw') {
            $rawBody = $data['body']['raw'] ?? '';
            if (! is_string($rawBody)) {
                throw ValidationException::withMessages([
                    'body.raw' => ['Raw body must be a string.'],
                ]);
            }

            if (strlen($rawBody) > $maxBodyBytes) {
                throw ValidationException::withMessages([
                    'body.raw' => ['Body exceeds maximum allowed size.'],
                ]);
            }
        } elseif ($bodyType === 'x-www-form-urlencoded') {
            $rows = $data['body']['form'] ?? [];
            $formParams = [];
            foreach ($rows as $row) {
                if (($row['type'] ?? null) !== 'text') {
                    continue;
                }
                $formParams[$row['key']] = (string) ($row['value'] ?? '');
            }
            $encoded = http_build_query($formParams);
            if (strlen($encoded) > $maxBodyBytes) {
                throw ValidationException::withMessages([
                    'body.form' => ['Body exceeds maximum allowed size.'],
                ]);
            }
        } elseif ($bodyType === 'form-data') {
            $rows = $data['body']['form'] ?? [];
            $multipart = [];
            $totalUploadBytes = 0;
            $maxUploadBytes = (int) env('PROXY_MAX_UPLOAD_BYTES', 5_000_000);

            foreach ($rows as $row) {
                if (($row['type'] ?? null) === 'text') {
                    $multipart[] = [
                        'name' => $row['key'],
                        'contents' => (string) ($row['value'] ?? ''),
                    ];
                }

                if (($row['type'] ?? null) === 'file') {
                    $fileKey = $row['file_key'] ?? null;
                    if (! is_string($fileKey) || $fileKey === '') {
                        continue;
                    }
                    $file = $request->file($fileKey);
                    if (! $file) {
                        continue;
                    }
                    $totalUploadBytes += (int) $file->getSize();

                    if ($totalUploadBytes > $maxUploadBytes) {
                        throw ValidationException::withMessages([
                            'files' => ['Uploaded files exceed maximum allowed size.'],
                        ]);
                    }

                    $multipart[] = [
                        'name' => $row['key'],
                        'contents' => fopen($file->getRealPath(), 'r'),
                        'filename' => $file->getClientOriginalName(),
                        'headers' => array_filter([
                            'Content-Type' => $file->getMimeType(),
                        ]),
                    ];
                }
            }
        } elseif ($bodyType === 'binary') {
            $fileKey = $data['body']['binary_file_key'] ?? null;
            if (! is_string($fileKey) || $fileKey === '') {
                throw ValidationException::withMessages([
                    'body.binary_file_key' => ['Binary file key is required.'],
                ]);
            }

            $file = $request->file($fileKey);
            if (! $file) {
                throw ValidationException::withMessages([
                    $fileKey => ['Binary file is required.'],
                ]);
            }

            $maxUploadBytes = (int) env('PROXY_MAX_UPLOAD_BYTES', 5_000_000);
            if ((int) $file->getSize() > $maxUploadBytes) {
                throw ValidationException::withMessages([
                    $fileKey => ['Binary upload exceeds maximum allowed size.'],
                ]);
            }

            $binary = fopen($file->getRealPath(), 'r');
            $binaryContentType = $binaryContentType ?: $file->getMimeType();
        }

        $start = hrtime(true);

        try {
            $client = Http::timeout(10)
                ->withOptions(['http_errors' => false])
                ->withHeaders($headers);

            $options = [
                'query' => $query ?: null,
            ];

            if ($this->allowsRequestBody($method)) {
                if ($multipart !== null) {
                    $options['multipart'] = $multipart;
                } elseif ($formParams !== null) {
                    $options['form_params'] = $formParams;
                } elseif ($binary !== null) {
                    $options['body'] = $binary;
                    if (is_string($binaryContentType) && $binaryContentType !== '') {
                        $client = $client->withHeaders(['Content-Type' => $binaryContentType]);
                    }
                } elseif ($rawBody !== null) {
                    $options['body'] = $rawBody;
                    if (is_string($rawContentType) && $rawContentType !== '') {
                        $client = $client->withHeaders(['Content-Type' => $rawContentType]);
                    }
                } elseif ($jsonBody !== null) {
                    $options['json'] = $jsonBody;
                }
            }

            $response = $client->send($method, $url, array_filter($options, fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Connection failed.',
            ], 502);
        }

        $elapsedMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $rawBody = $response->body();
        $maxResponseBytes = (int) env('PROXY_MAX_RESPONSE_BYTES', 1_000_000);
        $truncated = false;

        if (strlen($rawBody) > $maxResponseBytes) {
            $rawBody = substr($rawBody, 0, $maxResponseBytes);
            $truncated = true;
        }

        $decodedJson = null;
        $contentType = $response->header('content-type');

        if (is_string($contentType) && Str::contains($contentType, 'application/json')) {
            $decodedJson = json_decode($rawBody, true);
        } else {
            $attempt = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedJson = $attempt;
            }
        }

        return response()->json([
            'ok' => $response->successful(),
            'status' => $response->status(),
            'time_ms' => $elapsedMs,
            'headers' => $response->headers(),
            'body' => $decodedJson ?? $rawBody,
            'body_is_json' => $decodedJson !== null,
            'truncated' => $truncated,
        ], 200);
    }

    private function allowsRequestBody(string $method): bool
    {
        return ! in_array($method, ['GET', 'HEAD'], true);
    }

    private function sanitizeOutboundHeaders(array $headers): array
    {
        $clean = [];

        foreach ($headers as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $normalizedKey = strtolower(trim($key));

            if (in_array($normalizedKey, ['host', 'content-length', 'connection'], true)) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            } elseif (is_bool($value) || is_numeric($value)) {
                $value = (string) $value;
            }

            if (! is_string($value)) {
                continue;
            }

            $clean[trim($key)] = $value;
        }

        if (! array_key_exists('Accept', $clean) && ! array_key_exists('accept', $clean)) {
            $clean['Accept'] = 'application/json, */*';
        }

        return $clean;
    }

    private function applyAuth(array $headers, array $query, ?array $auth): array
    {
        if (! is_array($auth)) {
            return [$headers, $query];
        }

        $type = strtolower((string) ($auth['type'] ?? 'none'));

        if ($type === 'bearer') {
            $token = $auth['bearer'] ?? null;
            if (is_string($token) && $token !== '') {
                $headers['Authorization'] = 'Bearer '.$token;
            }
        } elseif ($type === 'basic') {
            $username = $auth['basic']['username'] ?? null;
            $password = $auth['basic']['password'] ?? null;
            if (is_string($username) && is_string($password)) {
                $headers['Authorization'] = 'Basic '.base64_encode($username.':'.$password);
            }
        } elseif ($type === 'api_key') {
            $in = strtolower((string) ($auth['api_key']['in'] ?? 'header'));
            $key = $auth['api_key']['key'] ?? null;
            $value = $auth['api_key']['value'] ?? null;

            if (is_string($key) && $key !== '' && is_string($value)) {
                if ($in === 'query') {
                    $query[$key] = $value;
                } else {
                    $headers[$key] = $value;
                }
            }
        }

        return [$headers, $query];
    }

    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            return false;
        }

        $hostLower = strtolower($host);
        if (in_array($hostLower, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }

        if (Str::endsWith($hostLower, '.local')) {
            return false;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowedPorts = array_values(array_filter(array_map('intval', explode(',', (string) env('PROXY_ALLOWED_PORTS', '80,443,8080,8443')))));
        if (! in_array($port, $allowedPorts, true)) {
            return false;
        }

        $ips = $this->resolveHostIps($hostLower);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }

            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);

            if ($normalized === '::1') {
                return false;
            }

            if (Str::startsWith($normalized, 'fe80:')) {
                return false;
            }

            if (Str::startsWith($normalized, 'fc') || Str::startsWith($normalized, 'fd')) {
                return false;
            }

            if (Str::startsWith($normalized, 'ff')) {
                return false;
            }

            return true;
        }

        return false;
    }
}
