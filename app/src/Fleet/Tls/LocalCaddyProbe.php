<?php

declare(strict_types=1);

namespace App\Fleet\Tls;

/**
 * Connects to this host's Caddy on 127.0.0.1:443 with the name as SNI and reads the expiry of the certificate it
 * presents. The chain is deliberately not verified (an expired or self-signed certificate is exactly what is looked
 * for); nothing is sent beyond the handshake.
 */
final readonly class LocalCaddyProbe implements CertificateProbe
{
    public function __construct(private string $address = 'tcp://127.0.0.1:443', private float $timeout = 5.0)
    {
    }

    public function probe(string $name): ?CertificateExpiry
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $name,
        ]]);
        $socket = @stream_socket_client($this->address, $code, $message, $this->timeout, \STREAM_CLIENT_CONNECT, $context);
        if (false === $socket) {
            return null;
        }
        try {
            stream_set_timeout($socket, (int) $this->timeout);
            if (true !== @stream_socket_enable_crypto($socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return new CertificateExpiry($name, null);
            }
            $params = stream_context_get_params($socket);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            $info = null === $cert ? false : @openssl_x509_parse($cert);
            $until = \is_array($info) && isset($info['validTo_time_t']) && \is_int($info['validTo_time_t']) ? $info['validTo_time_t'] : null;

            return new CertificateExpiry($name, null === $until ? null : new \DateTimeImmutable('@'.$until));
        } finally {
            fclose($socket);
        }
    }
}
