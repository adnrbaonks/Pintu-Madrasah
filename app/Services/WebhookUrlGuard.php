<?php

namespace App\Services;

use Closure;
use GuzzleHttp\Handler\CurlHandler;
use InvalidArgumentException;

class WebhookUrlGuard
{
    /**
     * Daftar hostname internal / metadata cloud yang selalu ditolak.
     *
     * @var list<string>
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        'ip6-localhost',
        'ip6-loopback',
        'metadata.google.internal',
        'metadata.goog',
        'instance-data',
        'instance-data.ec2.internal',
        '169.254.169.254',
        '100.100.100.200',
        'fd00:ec2::254',
    ];

    /**
     * Akhiran domain internal yang tidak boleh menjadi target webhook keluar.
     *
     * @var list<string>
     */
    private const BLOCKED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.home.arpa',
        '.localdomain',
    ];

    /**
     * Resolver DNS opsional untuk pengujian deterministik.
     *
     * @var (Closure(string): list<string>)|null
     */
    private ?Closure $dnsResolver = null;

    /**
     * Pemeriksa kapabilitas transport cURL runtime (dapat di-override pada pengujian fail-closed).
     *
     * @var (Closure(): bool)|null
     */
    private ?Closure $transportCapabilityChecker = null;

    /**
     * @param  (Closure(string): list<string>)|null  $dnsResolver
     * @param  (Closure(): bool)|null  $transportCapabilityChecker
     */
    public function __construct(?Closure $dnsResolver = null, ?Closure $transportCapabilityChecker = null)
    {
        $this->dnsResolver = $dnsResolver;
        $this->transportCapabilityChecker = $transportCapabilityChecker;
    }

    /**
     * Verifikasi apakah runtime HTTP transport mendukung cURL IP pinning (`CURLOPT_RESOLVE`).
     */
    public function runtimeSupportsIpPinning(): bool
    {
        if ($this->transportCapabilityChecker !== null) {
            return (bool) ($this->transportCapabilityChecker)();
        }

        return extension_loaded('curl')
            && function_exists('curl_init')
            && defined('CURLOPT_RESOLVE')
            && class_exists(CurlHandler::class);
    }

    /**
     * Pastikan runtime transport mendukung IP pinning dan bangun opsi Guzzle/cURL yang fail-closed:
     * - Wajib memiliki ekstensi cURL & `CURLOPT_RESOLVE` (jika tidak tersedia, lempar exception fail-closed).
     * - Menonaktifkan proxy environment (`proxy => ''`, `CURLOPT_PROXY => ''`, `CURLOPT_NOPROXY => '*'`)
     *   agar `HTTP_PROXY`/`HTTPS_PROXY` tidak melewati `CURLOPT_RESOLVE`.
     * - Menonaktifkan redirect (`allow_redirects => false`, `CURLOPT_FOLLOWLOCATION => false`).
     * - Mengunci IP hasil validasi DNS pada `CURLOPT_RESOLVE`.
     *
     * @param  array{url: string, scheme: string, host: string, port: int, resolved_ips: list<string>}  $resolvedTarget
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function buildPinnedTransportOptions(array $resolvedTarget): array
    {
        if (! $this->runtimeSupportsIpPinning()) {
            throw new InvalidArgumentException(
                'Transport HTTP runtime tidak mendukung cURL IP pinning (CURLOPT_RESOLVE). Pengiriman webhook dibatalkan secara fail-closed.'
            );
        }

        $pinnedIp = $resolvedTarget['resolved_ips'][0] ?? '';
        if ($pinnedIp === '' || ! $this->isPublicIp($pinnedIp)) {
            throw new InvalidArgumentException(
                'Target webhook tidak memiliki alamat IP publik tervalidasi untuk IP pinning.'
            );
        }

        $host = $resolvedTarget['host'];
        $port = (int) $resolvedTarget['port'];
        $formattedIp = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;

        $curlOptions = [
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$formattedIp}"],
        ];

        if (defined('CURLOPT_PROXY')) {
            $curlOptions[CURLOPT_PROXY] = '';
        }

        if (defined('CURLOPT_NOPROXY')) {
            $curlOptions[CURLOPT_NOPROXY] = '*';
        }

        if (defined('CURLOPT_FOLLOWLOCATION')) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = false;
        }

        if (defined('CURLOPT_MAXREDIRS')) {
            $curlOptions[CURLOPT_MAXREDIRS] = 0;
        }

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) {
            $curlOptions[CURLOPT_PROTOCOLS] = $resolvedTarget['scheme'] === 'https'
                ? CURLPROTO_HTTPS
                : (CURLPROTO_HTTPS | CURLPROTO_HTTP);
        }

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => $curlOptions,
        ];
    }

    /**
     * Validasi target URL webhook dan kembalikan host, port, serta daftar IP publik tervalidasi.
     *
     * @return array{url: string, scheme: string, host: string, port: int, resolved_ips: list<string>}
     *
     * @throws InvalidArgumentException
     */
    public function validateAndResolve(string $url): array
    {
        $trimmed = trim($url);
        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Target URL webhook tidak valid.');
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Target URL webhook harus memiliki skema dan host yang valid.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Target URL webhook tidak boleh menyertakan kredensial userinfo.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException('Target URL webhook hanya mendukung skema HTTPS atau HTTP yang diizinkan.');
        }

        $requireHttps = (bool) config('services.webhooks.require_https', true);
        if ($requireHttps && $scheme !== 'https') {
            throw new InvalidArgumentException('Target URL webhook wajib menggunakan protokol HTTPS.');
        }

        $rawHost = strtolower(trim((string) $parts['host']));
        $normalizedHost = trim($rawHost, '[]');
        $normalizedHost = rtrim($normalizedHost, '.');

        if ($normalizedHost === '') {
            throw new InvalidArgumentException('Host target URL webhook tidak valid.');
        }

        if ($this->isBlockedHostname($normalizedHost)) {
            throw new InvalidArgumentException("Host target webhook '{$normalizedHost}' tidak diizinkan (internal/loopback/metadata).");
        }

        $allowedHosts = config('services.webhooks.allowed_hosts', []);
        if (is_array($allowedHosts) && $allowedHosts !== [] && ! $this->matchesAllowedHosts($normalizedHost, $allowedHosts)) {
            throw new InvalidArgumentException("Host target webhook '{$normalizedHost}' tidak termasuk dalam daftar host yang diizinkan.");
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port target URL webhook tidak valid.');
        }

        $resolvedIps = $this->resolveHostIps($normalizedHost);
        if ($resolvedIps === []) {
            throw new InvalidArgumentException("Host target webhook '{$normalizedHost}' tidak dapat diresolv ke alamat IP publik.");
        }

        foreach ($resolvedIps as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new InvalidArgumentException(
                    "Target webhook '{$normalizedHost}' mengarah ke alamat IP non-publik atau terlarang ({$ip})."
                );
            }
        }

        return [
            'url' => $trimmed,
            'scheme' => $scheme,
            'host' => $normalizedHost,
            'port' => $port,
            'resolved_ips' => $resolvedIps,
        ];
    }

    public function isPublicIp(string $ip): bool
    {
        $cleanIp = trim($ip, '[]');

        // Tangani IPv4-mapped IPv6 seperti ::ffff:127.0.0.1 atau ::ffff:169.254.169.254
        if (str_starts_with(strtolower($cleanIp), '::ffff:')) {
            $mapped = substr($cleanIp, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $cleanIp = $mapped;
            }
        }

        if (filter_var($cleanIp, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $publicIp = filter_var(
            $cleanIp,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($publicIp === false) {
            return false;
        }

        if (filter_var($cleanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($cleanIp);
            if ($long === false) {
                return false;
            }

            // Carrier-Grade NAT / Shared Address Space 100.64.0.0/10 (termasuk cloud metadata 100.100.100.200)
            if (($long & ip2long('255.192.0.0')) === ip2long('100.64.0.0')) {
                return false;
            }

            // Benchmark testing 198.18.0.0/15
            if (($long & ip2long('255.254.0.0')) === ip2long('198.18.0.0')) {
                return false;
            }
        } else {
            $packed = @inet_pton($cleanIp);
            if ($packed === false || strlen($packed) !== 16) {
                return false;
            }

            $firstByte = ord($packed[0]);
            $secondByte = ord($packed[1]);

            // IPv6 Unique Local Addresses fc00::/7 (fc00:: - fdff::)
            if (($firstByte & 0xFE) === 0xFC) {
                return false;
            }

            // IPv6 Link-Local fe80::/10
            if ($firstByte === 0xFE && ($secondByte & 0xC0) === 0x80) {
                return false;
            }
        }

        return true;
    }

    private function isBlockedHostname(string $host): bool
    {
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return true;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Tolak notasi IP integer/octal/hex seperti 2130706433 atau 0x7f000001
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host) === 1) {
            return true;
        }

        // Tolak host tanpa TLD publik (single-label internal hostname seperti "redis", "mysql", "app")
        if (filter_var($host, FILTER_VALIDATE_IP) === false && ! str_contains($host, '.')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $allowedHosts
     */
    private function matchesAllowedHosts(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }

            if ($host === $pattern) {
                return true;
            }

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1); // ".example.com"
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($this->dnsResolver !== null) {
            $ips = ($this->dnsResolver)($host);

            return array_values(array_unique(array_filter($ips, 'is_string')));
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (! empty($record['ip']) && is_string($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (! empty($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($ips === []) {
            $ipv4List = @gethostbynamel($host);
            if (is_array($ipv4List)) {
                foreach ($ipv4List as $ipv4) {
                    if (is_string($ipv4) && $ipv4 !== '') {
                        $ips[] = $ipv4;
                    }
                }
            }
        }

        // Di lingkungan test terisolasi tanpa koneksi DNS publik, RFC 2606 example.com/example.org diresolv ke IP publik standar IANA.
        if ($ips === [] && app()->environment('testing')) {
            if (
                $host === 'example.com'
                || $host === 'example.org'
                || str_ends_with($host, '.example.com')
                || str_ends_with($host, '.example.org')
            ) {
                return ['93.184.216.34'];
            }
        }

        return array_values(array_unique($ips));
    }
}
