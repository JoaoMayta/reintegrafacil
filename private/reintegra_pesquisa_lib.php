<?php

declare(strict_types=1);

class ReintegraPesquisaConflictException extends RuntimeException {}

function reintegra_pesquisa_ensure_submissions_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS pesquisa_submissoes (
        chave CHAR(32) PRIMARY KEY,
        public_id CHAR(32) NULL,
        payload_hash CHAR(64) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!$pdo->query("SHOW COLUMNS FROM pesquisa_submissoes LIKE 'payload_hash'")->fetch()) {
        try {
            $pdo->exec('ALTER TABLE pesquisa_submissoes ADD COLUMN payload_hash CHAR(64) NULL AFTER public_id');
        } catch (PDOException $exception) {
            // Outra requisição pode concluir a mesma migração aditiva.
            if (!$pdo->query("SHOW COLUMNS FROM pesquisa_submissoes LIKE 'payload_hash'")->fetch()) throw $exception;
        }
    }
    $ready = true;
}

function reintegra_pesquisa_idempotent_save(PDO $pdo, array $response, string $key): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $key)) throw new InvalidArgumentException('Chave de envio inválida.');
    // DDL antecede a transação para não provocar commit implícito no envio.
    reintegra_pesquisa_ensure_schema($pdo);
    reintegra_pesquisa_ensure_submissions_schema($pdo);
    $canonical = $response;
    foreach (['devices', 'main_needs', 'obstacles', 'desired_features'] as $field) {
        if (isset($canonical[$field]) && is_array($canonical[$field])) sort($canonical[$field], SORT_STRING);
    }
    $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('INSERT IGNORE INTO pesquisa_submissoes(chave,payload_hash) VALUES (?,?)');
        $statement->execute([$key, $hash]);
        $statement = $pdo->prepare('SELECT public_id,payload_hash FROM pesquisa_submissoes WHERE chave=? FOR UPDATE');
        $statement->execute([$key]);
        $submission = $statement->fetch();
        if (!$submission || !is_string($submission['payload_hash']) || !hash_equals($submission['payload_hash'], $hash)) {
            throw new ReintegraPesquisaConflictException('Este envio já foi registrado. Não foi possível confirmar que as respostas são as mesmas. A resposta original foi preservada e estas alterações não foram salvas.');
        }
        $publicId = $submission['public_id'];
        if (!$publicId) {
            $publicId = reintegra_pesquisa_save($pdo, $response);
            $statement = $pdo->prepare('UPDATE pesquisa_submissoes SET public_id=? WHERE chave=?');
            $statement->execute([$publicId, $key]);
        }
        $pdo->commit();
        return $publicId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function reintegra_pesquisa_config(): array
{
    static $config;

    if (is_array($config)) {
        return $config;
    }

    $path = __DIR__ . '/reintegra_pesquisa_config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Configuração da pesquisa não encontrada.');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('Configuração da pesquisa inválida.');
    }

    $config = $loaded;
    return $config;
}

function reintegra_pesquisa_db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = reintegra_pesquisa_config();
    $database = $config['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('Banco da pesquisa não configurado.');
    }

    $pdo = new PDO(
        (string) ($database['dsn'] ?? ''),
        (string) ($database['username'] ?? ''),
        (string) ($database['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function reintegra_pesquisa_ensure_schema(PDO $pdo): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS pesquisa_respostas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    respondent_type VARCHAR(64) NOT NULL,
    adult_confirmed TINYINT(1) NOT NULL,
    participation_consent TINYINT(1) NOT NULL,
    internet_access VARCHAR(64) NOT NULL,
    devices_json TEXT NOT NULL,
    main_needs_json TEXT NOT NULL,
    obstacles_json TEXT NOT NULL,
    clarity_expectation TINYINT UNSIGNED NOT NULL,
    desired_features_json TEXT NOT NULL,
    first_priority VARCHAR(64) NULL,
    suggestion TEXT NULL,
    research_use_consent TINYINT(1) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pesquisa_respostas_public_id (public_id),
    KEY idx_pesquisa_respostas_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $ready = true;
}

function reintegra_pesquisa_save(PDO $pdo, array $response): string
{
    reintegra_pesquisa_ensure_schema($pdo);

    $publicId = bin2hex(random_bytes(16));
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO pesquisa_respostas (
    public_id,
    created_at,
    respondent_type,
    adult_confirmed,
    participation_consent,
    internet_access,
    devices_json,
    main_needs_json,
    obstacles_json,
    clarity_expectation,
    desired_features_json,
    first_priority,
    suggestion,
    research_use_consent
) VALUES (
    :public_id,
    :created_at,
    :respondent_type,
    :adult_confirmed,
    :participation_consent,
    :internet_access,
    :devices_json,
    :main_needs_json,
    :obstacles_json,
    :clarity_expectation,
    :desired_features_json,
    :first_priority,
    :suggestion,
    :research_use_consent
)
SQL
    );

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    $statement->execute([
        'public_id' => $publicId,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'respondent_type' => $response['respondent_type'],
        'adult_confirmed' => 1,
        'participation_consent' => 1,
        'internet_access' => $response['internet_access'],
        'devices_json' => json_encode($response['devices'], $jsonFlags),
        'main_needs_json' => json_encode($response['main_needs'], $jsonFlags),
        'obstacles_json' => json_encode($response['obstacles'], $jsonFlags),
        'clarity_expectation' => $response['clarity_expectation'],
        'desired_features_json' => json_encode($response['desired_features'], $jsonFlags),
        'first_priority' => $response['first_priority'] !== '' ? $response['first_priority'] : null,
        'suggestion' => $response['suggestion'] !== '' ? $response['suggestion'] : null,
        'research_use_consent' => 1,
    ]);

    return $publicId;
}

function reintegra_pesquisa_all(PDO $pdo): array
{
    reintegra_pesquisa_ensure_schema($pdo);
    $statement = $pdo->query(
        'SELECT public_id, created_at, respondent_type, internet_access, devices_json, main_needs_json, obstacles_json, clarity_expectation, desired_features_json, first_priority, suggestion FROM pesquisa_respostas ORDER BY created_at DESC, id DESC'
    );

    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        foreach (['devices', 'main_needs', 'obstacles', 'desired_features'] as $field) {
            $jsonField = $field . '_json';
            $decoded = json_decode((string) ($row[$jsonField] ?? '[]'), true);
            $row[$field] = is_array($decoded) ? $decoded : [];
            unset($row[$jsonField]);
        }
    }
    unset($row);

    return $rows;
}

