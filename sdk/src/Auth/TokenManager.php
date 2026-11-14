<?php
namespace UIC\SDK\Auth;

/**
 * Token 管理器（内存 + 文件持久化）
 */
class TokenManager
{
    private $token;
    private $refreshToken;
    private $expiresAt;
    private $storagePath;

    public function __construct(string $storagePath = '')
    {
        $this->storagePath = $storagePath;
        if ($storagePath && file_exists($storagePath)) {
            $data = json_decode(file_get_contents($storagePath), true);
            if (is_array($data)) {
                $this->token = $data['token'] ?? null;
                $this->refreshToken = $data['refresh_token'] ?? null;
                $this->expiresAt = $data['expires_at'] ?? null;
            }
        }
    }

    public function setToken(string $token, ?string $refreshToken = null, ?int $expiresIn = null): void
    {
        $this->token = $token;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresIn ? time() + $expiresIn : null;
        $this->persist();
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function isExpired(): bool
    {
        if (!$this->expiresAt) return false;
        return time() >= $this->expiresAt - 60; // 提前 60 秒过期
    }

    public function clear(): void
    {
        $this->token = null;
        $this->refreshToken = null;
        $this->expiresAt = null;
        if ($this->storagePath && file_exists($this->storagePath)) {
            unlink($this->storagePath);
        }
    }

    private function persist(): void
    {
        if (!$this->storagePath) return;
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->storagePath, json_encode([
            'token'         => $this->token,
            'refresh_token' => $this->refreshToken,
            'expires_at'    => $this->expiresAt,
        ]), LOCK_EX);
    }
}
