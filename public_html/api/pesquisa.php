<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/private/reintegra_pesquisa_lib.php';

function pesquisa_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pesquisa_choice(mixed $value, array $allowed, string $field): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new InvalidArgumentException("Campo inválido: {$field}.");
    }

    return $value;
}

function pesquisa_list(mixed $value, array $allowed, string $field, int $max = 8): array
{
    if (!is_array($value) || count($value) < 1 || count($value) > $max) {
        throw new InvalidArgumentException("Escolha pelo menos uma opção em {$field}.");
    }

    $result = [];
    foreach ($value as $item) {
        if (!is_string($item) || !in_array($item, $allowed, true)) {
            throw new InvalidArgumentException("Campo inválido: {$field}.");
        }
        if (!in_array($item, $result, true)) {
            $result[] = $item;
        }
    }

    return $result;
}

function pesquisa_text(mixed $value, string $field, int $maxLength): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException("Campo inválido: {$field}.");
    }

    $value = trim($value);
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException("O campo {$field} é muito longo.");
    }

    return $value;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pesquisa_response(405, ['success' => false, 'error' => 'Método não permitido.']);
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw === false ? '' : $raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Envie uma resposta válida.');
    }

    $response = [
        'respondent_type' => pesquisa_choice($payload['respondent_type'] ?? null, [
            'pessoa_privada_liberdade',
            'pessoa_egressa',
            'familiar_responsavel',
            'profissional_apoiador',
            'prefiro_nao_responder',
        ], 'perfil'),
        'adult_confirmation' => pesquisa_choice($payload['adult_confirmation'] ?? null, ['sim'], 'idade'),
        'participation_consent' => pesquisa_choice($payload['participation_consent'] ?? null, ['sim'], 'consentimento'),
        'internet_access' => pesquisa_choice($payload['internet_access'] ?? null, [
            'todos_os_dias',
            'algumas_vezes_semana',
            'raramente',
            'nao_acesso',
            'alguem_acessa_por_mim',
        ], 'acesso à internet'),
        'devices' => pesquisa_list($payload['devices'] ?? null, [
            'celular',
            'computador',
            'dispositivo_compartilhado',
            'nao_sei_usar',
        ], 'dispositivo'),
        'main_needs' => pesquisa_list($payload['main_needs'] ?? null, [
            'documentos',
            'estudo',
            'qualificacao',
            'trabalho',
            'apoio',
            'outro',
        ], 'necessidade'),
        'obstacles' => pesquisa_list($payload['obstacles'] ?? null, [
            'nao_sei_onde_procurar',
            'internet_dificil',
            'linguagem_dificil',
            'medo_desconfianca',
            'informacao_desatualizada',
            'preciso_de_ajuda',
            'outro',
        ], 'dificuldade'),
        'clarity_expectation' => 0,
        'desired_features' => pesquisa_list($payload['desired_features'] ?? null, [
            'passo_a_passo',
            'links_oficiais',
            'busca_por_cidade',
            'checklist_documentos',
            'vagas_cursos',
            'acessibilidade',
        ], 'recurso desejado'),
        'first_priority' => '',
        'suggestion' => pesquisa_text($payload['suggestion'] ?? '', 'sugestão', 600),
        'research_use_consent' => pesquisa_choice($payload['research_use_consent'] ?? null, ['sim'], 'uso acadêmico'),
    ];

    $clarity = filter_var($payload['clarity_expectation'] ?? null, FILTER_VALIDATE_INT);
    if ($clarity === false || $clarity < 1 || $clarity > 5) {
        throw new InvalidArgumentException('Escolha uma nota de 1 a 5.');
    }
    $response['clarity_expectation'] = $clarity;

    $firstPriority = $payload['first_priority'] ?? '';
    if ($firstPriority !== '') {
        $response['first_priority'] = pesquisa_choice($firstPriority, [
            'documentos',
            'estudo',
            'qualificacao',
            'trabalho',
            'apoio',
            'outro',
        ], 'primeira prioridade');
    }

    $pdo = reintegra_pesquisa_db();
    $key = $payload['submission_key'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/', $key)) {
        throw new InvalidArgumentException('Atualize a página antes de enviar sua resposta.');
    }
    $publicId = reintegra_pesquisa_idempotent_save($pdo, $response, $key);

    pesquisa_response(201, [
        'success' => true,
        'id' => $publicId,
        'message' => 'Resposta registrada com sucesso.',
    ]);
} catch (ReintegraPesquisaConflictException $exception) {
    pesquisa_response(409, ['success' => false, 'error' => $exception->getMessage()]);
} catch (InvalidArgumentException | JsonException $exception) {
    pesquisa_response(422, ['success' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('ReintegraFacil pesquisa: ' . $exception->getMessage());
    pesquisa_response(500, [
        'success' => false,
        'error' => 'Não foi possível registrar agora. Tente novamente em alguns instantes.',
    ]);
}
