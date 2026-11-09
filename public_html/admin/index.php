<?php

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

ini_set('session.use_strict_mode', '1');
session_name('reintegra_pesquisa_admin');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

require_once dirname(__DIR__, 2) . '/private/reintegra_pesquisa_lib.php';
require_once dirname(__DIR__, 2) . '/private/reintegra_auth_guard.php';

function admin_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_csrf(): string
{
    if (empty($_SESSION['reintegra_csrf'])) {
        $_SESSION['reintegra_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['reintegra_csrf'];
}

function admin_check_csrf(): void
{
    $sessionToken = (string) ($_SESSION['reintegra_csrf'] ?? '');
    $postedToken = (string) ($_POST['csrf'] ?? '');
    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function admin_csv_value(mixed $value): string
{
    if (is_array($value)) {
        $value = implode(' | ', $value);
    }

    $value = (string) $value;
    if (preg_match('/^[\s\x00-\x1F\x{FEFF}]*[=+@-]/u', $value)) $value = "'" . $value;
    return '"' . str_replace(["\"", "\r", "\n"], ['""', ' ', ' '], (string) $value) . '"';
}

function admin_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if (!$date) {
        return $value;
    }

    return $date->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
}

function admin_label(string $value): string
{
    $labels = [
        'pessoa_privada_liberdade' => 'Pessoa atualmente privada de liberdade',
        'pessoa_egressa' => 'Pessoa que já deixou o sistema prisional',
        'familiar_responsavel' => 'Familiar ou responsável',
        'profissional_apoiador' => 'Profissional ou pessoa apoiadora',
        'prefiro_nao_responder' => 'Prefiro não responder',
        'todos_os_dias' => 'Todos os dias',
        'algumas_vezes_semana' => 'Algumas vezes por semana',
        'raramente' => 'Raramente',
        'nao_acesso' => 'Não acesso',
        'alguem_acessa_por_mim' => 'Alguém acessa por mim',
        'documentos' => 'Documentos',
        'estudo' => 'Estudo',
        'qualificacao' => 'Cursos e qualificação',
        'trabalho' => 'Trabalho e renda',
        'apoio' => 'Apoio social, saúde ou direitos',
        'outro' => 'Outro',
        'celular' => 'Celular',
        'computador' => 'Computador ou notebook',
        'dispositivo_compartilhado' => 'Dispositivo compartilhado',
        'nao_sei_usar' => 'Preciso de ajuda para usar',
        'nao_sei_onde_procurar' => 'Não sei onde procurar',
        'internet_dificil' => 'Internet ou sinal ruim',
        'linguagem_dificil' => 'Linguagem difícil',
        'medo_desconfianca' => 'Medo ou desconfiança',
        'informacao_desatualizada' => 'Informação desatualizada',
        'preciso_de_ajuda' => 'Preciso de alguém para ajudar',
        'passo_a_passo' => 'Passo a passo simples',
        'links_oficiais' => 'Links oficiais e confiáveis',
        'busca_por_cidade' => 'Busca por cidade',
        'checklist_documentos' => 'Checklist de documentos',
        'vagas_cursos' => 'Vagas e cursos',
        'acessibilidade' => 'Áudio, contraste e letra maior',
    ];

    return $labels[$value] ?? $value;
}

$csrf = admin_csrf();
$error = '';
$notice = '';
$authenticated = !empty($_SESSION['reintegra_pesquisa_admin']);
$config = [];

try {
    $config = reintegra_pesquisa_config();
} catch (Throwable $exception) {
    $error = 'Configuração privada da área do pesquisador não encontrada.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_check_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'login') {
            $hash = (string) ($config['admin_password_hash'] ?? '');
            $password = is_string($_POST['password']??null) ? $_POST['password'] : '';
            $loginToken=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),$hash);
            try { reintegra_auth_attempt(reintegra_pesquisa_db(),$loginToken); }
            catch (PDOException $exception) { error_log('ReintegraFacil limite de login: '.$exception->getMessage()); throw new RuntimeException('Não foi possível conferir o acesso agora. Tente novamente.'); }
            if ($hash === '' || !password_verify($password, $hash)) {
                throw new RuntimeException('Senha inválida.');
            }

            reintegra_auth_reset(reintegra_pesquisa_db(),$loginToken);
            session_regenerate_id(true);
            $_SESSION['reintegra_pesquisa_admin'] = true;
            header('Location: /admin/');
            exit;
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: /admin/');
            exit;
        }
    } catch (Throwable $exception) {
        if ($exception instanceof ReintegraAuthRateLimit) { http_response_code(429); header('Retry-After: 600'); }
        $error = $exception->getMessage();
    }
}

$authenticated = !empty($_SESSION['reintegra_pesquisa_admin']);
$responses = [];

if ($authenticated && $error === '') {
    try {
        $responses = reintegra_pesquisa_all(reintegra_pesquisa_db());
        $totalResponses = count($responses);
        foreach ($responses as $index => &$response) {
            $response['response_number'] = $totalResponses - $index;
        }
        unset($response);
    } catch (Throwable $exception) {
        $error = 'Não foi possível consultar as respostas agora.';
        error_log('ReintegraFacil admin: ' . $exception->getMessage());
    }
}

if ($authenticated && isset($_GET['export']) && $_GET['export'] === 'csv' && $error === '') {
    $columns = [
        'response_number' => 'numero_resposta',
        'created_at' => 'data_hora',
        'respondent_type' => 'perfil',
        'internet_access' => 'acesso_internet',
        'devices' => 'dispositivos',
        'main_needs' => 'assuntos_prioritarios',
        'obstacles' => 'obstaculos',
        'clarity_expectation' => 'facilidade_esperada',
        'desired_features' => 'recursos_desejados',
        'first_priority' => 'primeiro_recurso',
        'suggestion' => 'sugestao',
    ];

    $lines = [implode(';', array_map('admin_csv_value', array_values($columns)))];
    foreach ($responses as $response) {
        $line = [];
        foreach (array_keys($columns) as $field) {
            $value = $response[$field] ?? '';
            if (in_array($field, ['respondent_type', 'internet_access', 'devices', 'main_needs', 'obstacles', 'desired_features', 'first_priority'], true)) {
                if (is_array($value)) {
                    $value = array_map('admin_label', $value);
                } elseif ($value !== '') {
                    $value = admin_label((string) $value);
                }
            }
            $line[] = admin_csv_value($value);
        }
        $lines[] = implode(';', $line);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reintegrafacil-pesquisa.csv"');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines);
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Área do pesquisador | ReintegraFacil</title>
  <style>
    :root { --black:#24231f; --ink:#2e2d28; --muted:#6e6a60; --paper:#f8f5ee; --white:#fffdf8; --gold:#d9a21b; --gold-pale:#fbf2d9; --line:#ded7c8; --green:#566e5f; --red:#b95c3d; }
    *,*::before,*::after { box-sizing:border-box; }
    body { min-width:320px; margin:0; background:var(--paper); color:var(--ink); font-family:Arial,Helvetica,sans-serif; line-height:1.55; }
    a { color:inherit; }
    .wrap { width:min(1160px,calc(100% - 32px)); margin:0 auto; }
    header { background:var(--black); color:var(--white); }
    .header-inner { display:flex; align-items:center; justify-content:space-between; gap:20px; min-height:86px; }
    .brand { color:var(--white); font-family:Arial Narrow,Impact,Arial,sans-serif; font-size:1.15rem; font-weight:900; letter-spacing:.03em; text-decoration:none; text-transform:uppercase; }
    .brand span { display:block; margin-top:4px; color:var(--gold); font-size:.58rem; letter-spacing:.16em; }
    .back { color:#e8e2d7; font-size:.73rem; font-weight:800; letter-spacing:.08em; text-decoration:none; text-transform:uppercase; }
    main { padding:55px 0 90px; }
    .topline { display:flex; align-items:end; justify-content:space-between; gap:20px; margin-bottom:35px; }
    .kicker { margin:0 0 12px; color:var(--gold); font-size:.72rem; font-weight:900; letter-spacing:.16em; text-transform:uppercase; }
    h1,h2,h3,button,summary { font-family:Arial Narrow,Impact,Arial,sans-serif; font-weight:900; text-transform:uppercase; }
    h1 { margin:0; font-size:clamp(2.5rem,6vw,5.2rem); letter-spacing:-.065em; line-height:.9; }
    h1 span { color:var(--gold); }
    h2 { margin:0 0 10px; font-size:2.1rem; letter-spacing:-.04em; line-height:1; }
    .lead { max-width:580px; margin:14px 0 0; color:var(--muted); }
    .actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .button { display:inline-flex; min-height:44px; align-items:center; justify-content:center; padding:0 16px; border:2px solid var(--gold); border-radius:999px; background:var(--gold); color:var(--black); cursor:pointer; font-size:.7rem; letter-spacing:.08em; text-decoration:none; }
    .button.secondary { border-color:var(--black); background:transparent; }
    .button:hover { transform:translateY(-1px); }
    .alert { margin:0 0 22px; padding:14px 16px; border-left:4px solid var(--red); background:#f6e3dc; color:#74351f; font-weight:700; }
    .login-card,.dashboard { border:1px solid var(--line); background:var(--white); box-shadow:0 20px 60px rgba(36,35,31,.1); }
    .login-card { max-width:520px; margin:35px auto 0; padding:clamp(25px,5vw,55px); }
    label { display:block; color:var(--muted); font-size:.78rem; font-weight:700; }
    input[type=password] { width:100%; min-height:52px; margin-top:8px; padding:0 14px; border:1px solid var(--line); background:var(--paper); color:var(--ink); font:inherit; }
    input:focus { border-color:var(--gold); outline:3px solid rgba(217,162,27,.25); outline-offset:2px; }
    .login-card .button { width:100%; margin-top:18px; }
    .privacy { margin-top:20px; color:var(--muted); font-size:.8rem; }
    .dashboard-head { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:28px; border-bottom:1px solid var(--line); }
    .stats { display:inline-flex; align-items:center; gap:8px; padding:10px 13px; border:1px solid var(--line); color:var(--green); font-size:.7rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
    .stats::before { width:8px; height:8px; border-radius:50%; background:var(--green); content:""; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse:collapse; min-width:950px; }
    th,td { padding:14px 16px; border-bottom:1px solid var(--line); vertical-align:top; text-align:left; }
    th { background:var(--black); color:var(--white); font-size:.68rem; letter-spacing:.07em; text-transform:uppercase; }
    td { color:var(--ink); font-size:.82rem; }
    td small { color:var(--muted); }
    .cell-list { display:flex; flex-wrap:wrap; gap:5px; max-width:260px; }
    .tag { padding:4px 7px; background:var(--gold-pale); color:var(--black); font-size:.69rem; }
    .suggestion { max-width:280px; white-space:pre-wrap; }
    .empty { padding:45px 28px; color:var(--muted); text-align:center; }
    footer { padding:30px 0; background:var(--black); color:#aaa59a; font-size:.75rem; }
    @media (max-width:700px) { .header-inner,.topline,.dashboard-head { align-items:flex-start; flex-direction:column; } .back { align-self:flex-end; } .dashboard-head { gap:15px; } main { padding-top:38px; } }
  </style>
<link rel="icon" type="image/svg+xml" href="/assets/identidade/favicon.svg?v=20261108-1"><link rel="stylesheet" href="/assets/identidade/marca.css?v=20261108-1">
</head>
<body><nav style="padding:20px;text-align:center"><a href="/admin/catalogo.php">Gerenciar serviços, categorias e revisões</a> · <a href="/sistema/?catalogo=1">Catálogo público</a></nav>
  <header>
    <div class="wrap header-inner">
      <a class="rf-brand brand" href="/" aria-label="ReintegraFacil, página inicial"><img class="rf-logo" src="/assets/identidade/reintegrafacil.svg?v=20261108-1" width="340" height="88" alt="ReintegraFacil" decoding="async"></a>
      <a class="back" href="/pesquisa.html">Voltar à pesquisa</a>
    </div>
  </header>
  <main class="wrap">
<?php if (!$authenticated): ?>
    <div class="topline">
      <div>
        <p class="kicker">Acesso restrito</p>
        <h1>Área do <span>pesquisador.</span></h1>
        <p class="lead">Consulte as respostas anonimizadas da pesquisa de necessidades.</p>
      </div>
    </div>
    <?php if ($error !== ''): ?><p class="alert" role="alert"><?= admin_h($error) ?></p><?php endif; ?>
    <form class="login-card" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
      <input type="hidden" name="action" value="login">
      <label for="password">Senha do pesquisador
        <input id="password" name="password" type="password" required autofocus>
      </label>
      <button class="button" type="submit">Entrar</button>
      <p class="privacy">As respostas não ficam disponíveis nesta tela sem autenticação.</p>
    </form>
<?php else: ?>
    <div class="topline">
      <div>
        <p class="kicker">Pesquisa de necessidades</p>
        <h1>Respostas <span>recebidas.</span></h1>
        <p class="lead">Dados sem nome, documento, IP ou identificador do participante.</p>
      </div>
      <div class="actions">
        <a class="button secondary" href="/admin/?export=csv">Baixar CSV</a>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= admin_h($csrf) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="button secondary" type="submit">Sair</button>
        </form>
      </div>
    </div>
    <?php if ($error !== ''): ?><p class="alert" role="alert"><?= admin_h($error) ?></p><?php endif; ?>
    <section class="dashboard" aria-labelledby="responses-title">
      <div class="dashboard-head">
        <h2 id="responses-title">Respostas</h2>
        <div class="stats"><?= count($responses) ?> <?= count($responses) === 1 ? 'resposta' : 'respostas' ?></div>
      </div>
      <?php if (!$responses): ?>
        <p class="empty">Ainda não há respostas registradas.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Nº</th><th>Data</th><th>Perfil</th><th>Acesso</th><th>Necessidades</th><th>Dificuldades</th><th>Nota</th><th>Prioridade</th><th>Transcrição / sugestão</th></tr></thead>
          <tbody>
          <?php foreach ($responses as $response): ?>
            <tr>
              <td><strong>Resposta <?= admin_h((string) $response['response_number']) ?></strong></td>
              <td><?= admin_h(admin_date((string) $response['created_at'])) ?></td>
              <td><?= admin_h(admin_label((string) $response['respondent_type'])) ?></td>
              <td><?= admin_h(admin_label((string) $response['internet_access'])) ?><br><small><?= admin_h(implode(', ', array_map('admin_label', $response['devices'] ?? []))) ?></small></td>
              <td><div class="cell-list"><?php foreach (($response['main_needs'] ?? []) as $item): ?><span class="tag"><?= admin_h(admin_label((string) $item)) ?></span><?php endforeach; ?></div></td>
              <td><div class="cell-list"><?php foreach (($response['obstacles'] ?? []) as $item): ?><span class="tag"><?= admin_h(admin_label((string) $item)) ?></span><?php endforeach; ?></div></td>
              <td><?= admin_h((string) $response['clarity_expectation']) ?>/5</td>
              <td><?= admin_h($response['first_priority'] !== null && $response['first_priority'] !== '' ? admin_label((string) $response['first_priority']) : '—') ?></td>
              <td class="suggestion"><?= admin_h($response['suggestion'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
<?php endif; ?>
  </main>
  <footer><div class="wrap">ReintegraFacil · área privada da pesquisa de necessidades</div></footer>
</body>
</html>
