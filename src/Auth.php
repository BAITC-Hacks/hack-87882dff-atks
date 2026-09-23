<?php
declare(strict_types=1);
namespace SmartStock;
use RedBeanPHP\R;

class Auth {
    public static function login(array $body): array {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $user = R::findOne('useraccount', 'email = ?', [$email]);
        if (!$user || !password_verify((string)($body['password'] ?? ''), (string)$user->password_hash)) {
            throw new ApiException('Неверная почта или пароль', 401);
        }
        $token = bin2hex(random_bytes(32));
        R::exec('INSERT INTO apitoken (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [$user->id, hash('sha256', $token), date('Y-m-d H:i:s', time() + 86400)]);
        return ['token' => $token, 'user' => self::publicUser($user->export())];
    }
    public static function user(?string $role = null): array {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $match)) { throw new ApiException('Войдите в систему', 401); }
        $user = R::getRow('SELECT u.* FROM useraccount u JOIN apitoken t ON t.user_id = u.id WHERE t.token_hash = ? AND t.expires_at > NOW()', [hash('sha256', $match[1])]);
        if (!$user) { throw new ApiException('Сессия истекла. Войдите снова.', 401); }
        if ($role && $user['role'] !== $role) { throw new ApiException('Действие доступно только менеджеру', 403); }
        return self::publicUser($user);
    }
    public static function logout(): void {
        $token = substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 7);
        R::exec('DELETE FROM apitoken WHERE token_hash = ?', [hash('sha256', $token)]);
    }
    private static function publicUser(array $user): array { return ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']]; }
}
