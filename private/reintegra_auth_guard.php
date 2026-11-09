<?php
declare(strict_types=1);
class ReintegraAuthRateLimit extends RuntimeException {}
function reintegra_auth_attempt(PDO $pdo,string $token): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS catalogo_login_limites (token CHAR(64) PRIMARY KEY,tentativas INT UNSIGNED NOT NULL,inicio DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $s=$pdo->prepare('INSERT INTO catalogo_login_limites(token,tentativas,inicio) VALUES (?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE tentativas=IF(inicio < UTC_TIMESTAMP()-INTERVAL 10 MINUTE,1,LEAST(tentativas+1,10000)),inicio=IF(inicio < UTC_TIMESTAMP()-INTERVAL 10 MINUTE,UTC_TIMESTAMP(),inicio)');$s->execute([$token]);
    $s=$pdo->prepare('SELECT tentativas FROM catalogo_login_limites WHERE token=?');$s->execute([$token]);
    if((int)$s->fetchColumn()>8)throw new ReintegraAuthRateLimit('Muitas tentativas de acesso. Aguarde dez minutos antes de tentar novamente.');
}
function reintegra_auth_reset(PDO $pdo,string $token): void {$s=$pdo->prepare('UPDATE catalogo_login_limites SET tentativas=0 WHERE token=?');$s->execute([$token]);}
