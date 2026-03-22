<?php

declare(strict_types=1);

namespace Kode\Process\Ssl;

/**
 * SSL/TLS 配置管理
 * 
 * 支持 SSL 加密传输，提供证书配置和验证
 */
final class SslContext
{
    private string $certFile;
    private string $keyFile;
    private ?string $caFile = null;
    private bool $verifyPeer = false;
    private bool $verifyHost = false;
    private int $verifyDepth = 10;
    private ?string $passphrase = null;
    private string $ciphers = 'DEFAULT';
    private bool $allowSelfSigned = false;
    private string $protocol = 'tlsv1.2';

    public function __construct(string $certFile, string $keyFile)
    {
        $this->certFile = $certFile;
        $this->keyFile = $keyFile;
    }

    public static function fromFiles(string $certFile, string $keyFile): self
    {
        return new self($certFile, $keyFile);
    }

    public static function fromPath(string $path, string $certName = 'cert.pem', string $keyName = 'key.pem'): self
    {
        return new self(
            rtrim($path, '/') . '/' . $certName,
            rtrim($path, '/') . '/' . $keyName
        );
    }

    public function setCaFile(string $caFile): self
    {
        $this->caFile = $caFile;
        return $this;
    }

    public function setVerifyPeer(bool $verify): self
    {
        $this->verifyPeer = $verify;
        return $this;
    }

    public function setVerifyHost(bool $verify): self
    {
        $this->verifyHost = $verify;
        return $this;
    }

    public function setVerifyDepth(int $depth): self
    {
        $this->verifyDepth = $depth;
        return $this;
    }

    public function setPassphrase(string $passphrase): self
    {
        $this->passphrase = $passphrase;
        return $this;
    }

    public function setCiphers(string $ciphers): self
    {
        $this->ciphers = $ciphers;
        return $this;
    }

    public function allowSelfSigned(bool $allow = true): self
    {
        $this->allowSelfSigned = $allow;
        return $this;
    }

    public function setProtocol(string $protocol): self
    {
        $this->protocol = $protocol;
        return $this;
    }

    public function getContextOptions(): array
    {
        $options = [
            'ssl' => [
                'local_cert' => $this->certFile,
                'local_pk' => $this->keyFile,
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyHost,
                'verify_depth' => $this->verifyDepth,
                'ciphers' => $this->ciphers,
                'allow_self_signed' => $this->allowSelfSigned,
            ]
        ];

        if ($this->caFile !== null) {
            $options['ssl']['cafile'] = $this->caFile;
        }

        if ($this->passphrase !== null) {
            $options['ssl']['passphrase'] = $this->passphrase;
        }

        return $options;
    }

    public function createStreamContext()
    {
        return stream_context_create($this->getContextOptions());
    }

    public function createServerSocket(string $host, int $port)
    {
        $context = $this->createStreamContext();
        $socket = stream_socket_server(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("SSL 服务启动失败: {$errstr} ({$errno})");
        }

        return $socket;
    }

    public function validate(): bool
    {
        if (!file_exists($this->certFile)) {
            throw new \RuntimeException("证书文件不存在: {$this->certFile}");
        }

        if (!file_exists($this->keyFile)) {
            throw new \RuntimeException("密钥文件不存在: {$this->keyFile}");
        }

        if (!is_readable($this->certFile)) {
            throw new \RuntimeException("证书文件不可读: {$this->certFile}");
        }

        if (!is_readable($this->keyFile)) {
            throw new \RuntimeException("密钥文件不可读: {$this->keyFile}");
        }

        return true;
    }

    public function getCertFile(): string
    {
        return $this->certFile;
    }

    public function getKeyFile(): string
    {
        return $this->keyFile;
    }
}
