<?php
declare(strict_types=1);
ini_set('session.use_strict_mode', '1');
session_name('reintegra_visitante');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/sistema/', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly'=>true, 'samesite'=>'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
function h(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$states = ['AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'];
$modules = [
 'documentos'=>['Documentos','Organizar meus documentos','Orientações para RG, CPF e carteira de trabalho.','▤'],
 'estudos'=>['Estudos','Voltar a estudar','Alfabetização, escola e caminhos para continuar aprendendo.','▧'],
 'cursos'=>['Cursos','Aprender uma profissão','Cursos gratuitos e qualificação para o seu próximo passo.','◇'],
 'trabalho'=>['Trabalho','Procurar trabalho','Currículo, entrevistas e oportunidades de emprego.','▣'],
 'apoio'=>['Apoio','Encontrar apoio','Acolhimento, direitos e serviços de apoio social.','♡'],
];
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$errorField = '';
$draft = ($_SESSION['preferencias'] ?? []) + ['state'=>'','city'=>'','priority'=>'','name'=>'','anonymous'=>false];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) { http_response_code(403); $error='Atualize a página e tente novamente.'; }
 else {
  $action = isset($_POST['action']) ? (is_string($_POST['action'])?$_POST['action']:'invalid') : 'save';
  if(!in_array($action,['save','clear','bookmark','clear_paths'],true)){http_response_code(400);$error='Escolha uma ação disponível na página.';}
  if ($action === 'bookmark') {
   require_once dirname(__DIR__,2).'/private/catalogo.php';
   $id=is_string($_POST['service_id']??null)&&ctype_digit($_POST['service_id'])?(int)$_POST['service_id']:0;
   $lookup=cat_db()->prepare('SELECT id FROM catalogo_servicos WHERE id=? AND publicado=1 AND status_editorial=\'publicado\'');$lookup->execute([$id]);
   if (!$lookup->fetchColumn()) { http_response_code(404); $error='Esse serviço não está disponível no catálogo.'; }
   else {
    $saved=$_SESSION['caminhos']??[];
    if (in_array($id,$saved,true)) { $saved=array_values(array_diff($saved,[$id])); $_SESSION['catalog_notice']='Caminho retirado da sua lista.'; }
    elseif (count($saved)<100) { $saved[]=$id; $_SESSION['catalog_notice']='Caminho guardado. Encontre-o em Meus caminhos.'; }
    $_SESSION['caminhos']=$saved;
    $raw=is_string($_POST['return_query']??null)?substr($_POST['return_query'],0,2000):'';parse_str($raw,$return);
    $safe=['catalogo'=>'1'];foreach(['q','categoria','uf','cidade','salvos','servico'] as $key)if(isset($return[$key])&&is_string($return[$key]))$safe[$key]=mb_substr($return[$key],0,100);
    header('Location: /sistema/?'.http_build_query($safe));exit;
   }
  }
  if ($action === 'clear_paths') { unset($_SESSION['caminhos']); $_SESSION['catalog_notice']='Os caminhos guardados foram retirados desta sessão.'; header('Location: /sistema/?catalogo=1&salvos=1'); exit; }
  if ($action === 'clear') {
   unset($_SESSION['preferencias'],$_SESSION['caminhos']); session_regenerate_id(true); header('Location: /sistema/'); exit;
  }
  if ($action === 'save') {
  $state = $_POST['state'] ?? ''; $city = $_POST['city'] ?? ''; $priority = $_POST['priority'] ?? '';
  $anonymous = isset($_POST['anonymous']);
  $name = $anonymous ? '' : ($_POST['name'] ?? '');
  if (!is_string($state) || !is_string($city) || !is_string($priority) || !is_string($name)) $error='Confira suas escolhas.';
  elseif (mb_strlen(trim($name)) > 60 || preg_match('/[\x00-\x1F<>]/u', $name)) { $error='Use apenas seu primeiro nome ou apelido, com até 60 letras.'; $errorField='name'; }
  elseif (($state !== '' && !isset($states[$state])) || ($priority !== '' && !isset($modules[$priority]))) { $error='Escolha uma das opções disponíveis.'; $errorField=$state!==''&&!isset($states[$state])?'state':'priority'; }
  elseif (mb_strlen(trim($city)) > 80 || preg_match('/[\x00-\x1F<>]/u', $city)) { $error='Informe apenas o nome da cidade, com até 80 letras.'; $errorField='city'; }
  elseif ($city !== '' && $state === '') { $error='Escolha também o estado da sua cidade.'; $errorField='state'; }
  else {
   session_regenerate_id(true);
   $_SESSION['preferencias']=['state'=>$state,'city'=>trim($city),'priority'=>$priority,'name'=>trim($name),'anonymous'=>$anonymous];
   header('Location: /sistema/'); exit;
  }
  if (is_string($state) && is_string($city) && is_string($priority) && is_string($name)) $draft=['state'=>$state,'city'=>$city,'priority'=>$priority,'name'=>$name,'anonymous'=>$anonymous];
  }
 }
}
$onboarding = isset($_GET['ajustar']) || $error !== '';
$showCatalog = !isset($_SESSION['preferencias']);
foreach (['catalogo','servico','categoria','q','uf','cidade','salvos'] as $routeKey) if(isset($_GET[$routeKey])) $showCatalog=true;
$location = $draft['city'] !== '' ? $draft['city'].' · '.$draft['state'] : ($states[$draft['state']] ?? 'Todos os lugares');
if (isset($_GET['servico'])) {
 require_once dirname(__DIR__,2).'/private/catalogo.php';
 $lookup=cat_db()->prepare('SELECT id,dados FROM catalogo_servicos WHERE id=? AND publicado=1 AND status_editorial=\'publicado\'');
 $lookup->execute([is_string($_GET['servico'])&&ctype_digit($_GET['servico'])&&$_GET['servico']===(string)(int)$_GET['servico']?(int)$_GET['servico']:0]);
 $pageService=$lookup->fetch();
 if (!$pageService) http_response_code(404);
 else $pageServiceTitle=json_decode($pageService['dados'],true)['servico']??'Serviço';
}

?>
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#171717"><title><?= isset($pageServiceTitle) ? h($pageServiceTitle) : 'Encontre seu caminho' ?> | ReintegraFacil</title><link rel="stylesheet" href="/assets/sistema.css?v=20261108-tlm"><link rel="stylesheet" href="/assets/catalogo.css?v=1"><link rel="stylesheet" href="/assets/sistema-tlm.css?v=20261108-final1"><script src="/assets/sistema.js?v=20261108-final1" defer></script><script src="/assets/companhia.js?v=20261108-final1" defer></script><script src="/assets/experiencia.js?v=20261108-final1" defer></script><link rel="icon" type="image/svg+xml" href="/assets/identidade/favicon.svg?v=20261108-1"><link rel="stylesheet" href="/assets/identidade/marca.css?v=20261108-1">
</head>
<body>
<a class="skip" href="#conteudo">Pular para o conteúdo</a>
<header class="app-header"><div class="wrap header-inner"><a class="rf-brand brand" href="/" aria-label="ReintegraFacil, página inicial"><img class="rf-logo" src="/assets/identidade/reintegrafacil.svg?v=20261108-1" width="340" height="88" alt="ReintegraFacil" decoding="async"></a><div class="header-actions"><button id="voiceToggle" class="voice-toggle" type="button" aria-pressed="false">Ouvir</button><details class="access-menu"><summary aria-label="Opções de acessibilidade" title="Acessibilidade">Aa</summary><div><button class="quiet" id="fontToggle" aria-pressed="false" type="button">A+ Letra maior</button><button class="quiet" id="contrastToggle" aria-pressed="false" type="button">Mais contraste</button><button class="quiet" id="motionToggle" aria-pressed="false" type="button">Menos movimento</button><button class="quiet" id="resetVisual" type="button">Restaurar visual</button><p class="access-help">Usa leitor de tela? Você pode desligar a voz do site no botão de som.</p></div></details></div></div></header>
<nav class="app-navigation wrap" aria-label="Navegação do sistema"><a href="/sistema/?catalogo=1" class="<?=!isset($_GET['salvos'])&&!$onboarding?'active':''?>">Buscar serviços</a><a href="/sistema/?catalogo=1&amp;salvos=1" class="<?=isset($_GET['salvos'])?'active':''?>">Guardados <span><?=count($_SESSION['caminhos']??[])?></span></a><a href="/sistema/?ajustar=1" class="<?=$onboarding?'active':''?>">Preferências</a></nav>
<main id="conteudo" tabindex="-1">
<div class="wrap inline-guide" role="region" aria-label="Orientação de uso"><p id="screenInstruction">Busque um serviço ou escolha um assunto.</p><button type="button" id="startGuide" hidden>Toque para ouvir</button></div>
<?php if (!$onboarding && $showCatalog): define("CATALOGO_LAYOUT",true); require __DIR__."/catalogo.php"; elseif ($onboarding): ?>
 <section class="intro wrap"><div><p class="kicker">SEU PRÓXIMO PASSO</p><h1>Vamos começar.<br><em>Do seu jeito.</em></h1><p class="lead">Conte só o que ajuda a organizar seus caminhos.</p><p class="badge">Gratuito · Sem cadastro · Sem senha</p></div><div class="intro-note"><span class="big-symbol" aria-hidden="true">↗</span><p>Um passo de cada vez.<br>Você escolhe por onde começar.</p></div></section>
 <section class="wrap setup"><form method="post" id="setupForm" class="paper">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="save">
  <div class="step-line"><span id="stepLabel" aria-live="polite">Etapa 1 de 3 · Seu nome</span><span>Tudo é opcional</span></div><div class="progress" aria-hidden="true"><span id="progressFill"></span></div>
  <?php if ($error): ?><p class="error" id="setupError" role="alert" tabindex="-1"><?= h($error) ?></p><?php endif; ?>
  <fieldset data-step="0"><legend>Como podemos chamar você?</legend><p class="muted">Pode ser seu primeiro nome ou um apelido.</p>
   <div class="question-head"><label for="name">Seu nome <span class="optional">(opcional)</span></label><button type="button" class="audio" data-read="Como podemos chamar você? Pode escrever ou falar seu primeiro nome ou apelido. Se preferir, marque Prefiro não me identificar. Não é preciso informar um nome para continuar.">◖ Ouvir</button></div>
   <div class="voice-field" id="nameField"><input id="name" name="name" <?= $errorField==='name'?'aria-invalid="true"':'' ?> maxlength="60" value="<?= h($draft['name']) ?>" placeholder="Primeiro nome ou apelido" autocomplete="given-name" aria-describedby="nameHelp<?= $errorField==='name'?' setupError':'' ?>"><button class="audio" type="button" data-dictate="name" aria-label="Falar meu nome">♩ Falar</button></div>
   <label class="anonymous-choice"><input id="anonymous" name="anonymous" type="checkbox" value="1" <?= $draft['anonymous']?'checked':'' ?>><span>Prefiro não me identificar</span></label>
   <p class="small" id="nameHelp">Você pode continuar sem preencher. Seu nome fica apenas nesta sessão.</p>
   <p class="small">O botão Falar usa o serviço de voz do navegador. Confira o texto transcrito.</p>
   <div class="form-actions"><button type="button" class="quiet" id="skipName">Continuar sem nome</button><button type="button" class="button" data-next-step>Continuar →</button></div>
  </fieldset>
  <fieldset data-step="1"><legend>Onde você está?</legend><p class="muted">Informe cidade e estado ou continue sem preencher.</p>
   <div class="question-head"><label for="state">Qual é o seu estado?</label><button type="button" class="audio" data-read="Qual é o seu estado? Escolha na lista. Se preferir, deixe em branco.">◖ Ouvir</button></div>
   <select id="state" name="state" aria-describedby="locationError<?= $errorField==='state'?' setupError':'' ?>" <?= $errorField==='state'?'aria-invalid="true"':'' ?>><option value="">Prefiro informar depois</option><?php foreach($states as $uf=>$name): ?><option value="<?= h($uf) ?>" <?= $draft['state']===$uf?'selected':'' ?>><?= h($name) ?> (<?= h($uf) ?>)</option><?php endforeach; ?></select>
   <div class="question-head"><label for="city">Qual é a sua cidade?</label><button type="button" class="audio" data-read="Qual é a sua cidade? Escreva só o nome da cidade, ou toque em Falar para usar o microfone.">◖ Ouvir</button></div>
   <div class="voice-field"><input id="city" name="city" aria-describedby="locationError<?= $errorField==='city'?' setupError':'' ?>" <?= $errorField==='city'?'aria-invalid="true"':'' ?> maxlength="80" value="<?= h($draft['city']) ?>" placeholder="Ex.: São Paulo" autocomplete="address-level2"><button class="audio" type="button" data-dictate="city">♩ Falar</button></div>
   <p class="small">Ao usar Falar, seu navegador pode enviar o áudio ao serviço de voz dele. Revise o texto antes de continuar.</p>
   <p class="error" id="locationError" role="alert"></p>
   <div class="form-actions"><button type="button" class="quiet" data-prev-step>← Voltar</button><button type="button" class="button" data-next-step>Continuar →</button></div>
  </fieldset>
  <fieldset data-step="2"><legend>O que você precisa agora?</legend><p class="muted">Escolha um caminho ou deixe para depois.</p><button type="button" class="audio" data-read="O que você precisa agora? Resposta 1: organizar meus documentos. Resposta 2: voltar a estudar. Resposta 3: aprender uma profissão. Resposta 4: procurar trabalho. Resposta 5: encontrar apoio. Resposta 6: decidir depois.">◖ Ouvir pergunta e respostas</button>
   <div class="choices" <?= $errorField==='priority'?'role="group" aria-label="O que você precisa agora?" aria-describedby="setupError" aria-invalid="true" tabindex="-1"':'' ?>><?php $n=0; foreach($modules as $key=>$module): $n++; ?><label class="choice"><input type="radio" name="priority" value="<?= h($key) ?>" <?= $draft['priority']===$key?'checked':'' ?>><span><b class="number"><?= $n ?></b><?= h($module[1]) ?></span></label><?php endforeach; ?><label class="choice"><input type="radio" name="priority" value="" <?= $draft['priority']===''?'checked':'' ?>><span><b class="number">6</b>Decidir depois</span></label></div>
   <p class="session-note">Usamos um cookie de sessão para lembrar suas escolhas neste navegador. Você pode alterar ou limpar quando quiser.</p>
   <div class="form-actions"><button type="button" class="quiet" data-prev-step>← Voltar</button><button type="submit" class="button">Abrir meu painel →</button></div>
  </fieldset>
  <noscript><p class="small">As respostas são opcionais. Toque em Abrir meu painel ao terminar.</p></noscript>
 </form><aside class="side-note"><p class="kicker">COMECE PELO QUE IMPORTA</p><h2>Não precisa<br>saber tudo<br><em>agora.</em></h2><p>Você pode mudar suas escolhas a qualquer momento.</p><a href="/sistema/?ajustar=1" hidden>Alterar preferências</a></aside></section>
<?php else: ?>
 <section class="dashboard-hero"><div class="wrap"><p class="kicker greeting"><?= $draft['name'] !== '' ? 'Olá, '.h($draft['name']).'.' : 'Que bom ter você aqui.' ?></p><div class="hero-row"><div><h1>Seu próximo passo<br><em>começa aqui.</em></h1><p class="lead">Escolha um caminho. Vamos no seu tempo.</p></div><div class="location"><span aria-hidden="true">⌖</span><div><small>SEU LUGAR</small><strong><?= h($location) ?></strong><a href="/sistema/?ajustar=1">Alterar minhas escolhas</a></div></div></div></div></section>
 <div class="wrap dashboard">
  <section class="search-bar" aria-label="Buscar caminhos"><label for="search">O que você procura?</label><div class="voice-field"><input id="search" type="search" placeholder="Ex.: documentos, cursos, trabalho" maxlength="100"><button class="audio" type="button" data-dictate="search">♩ Falar</button><button class="audio" type="button" data-read="O que você procura? Digite uma palavra ou toque em Falar. A busca filtra os caminhos deste painel.">◖ Ouvir</button></div><p class="small">Falar usa o serviço de voz do navegador. Confira o texto transcrito.</p></section>
  <div class="section-head"><div><p class="kicker">ESCOLHA UM CAMINHO</p><h2>Por onde vamos começar?</h2></div><button type="button" class="audio" data-read="Escolha um caminho: documentos, estudos, cursos, trabalho ou apoio. Consulte orientações e fontes oficiais.">◖ Ouvir painel</button></div>
  <div class="module-grid"><?php $sorted=$modules; if (isset($modules[$draft['priority']])) $sorted=[$draft['priority']=>$modules[$draft['priority']]]+$modules; foreach($sorted as $key=>$module): ?>
   <article class="module <?= $draft['priority']===$key?'featured':'' ?>" data-module="<?= h($module[0].' '.$module[1].' '.$module[2]) ?>"><div class="module-top"><span class="module-icon" aria-hidden="true"><?= h($module[3]) ?></span><span class="pill"><?= $draft['priority']===$key?'SUA PRIORIDADE':'CONSULTAR' ?></span></div><h3><?= h($module[0]) ?></h3><p><?= h($module[2]) ?></p><div class="module-actions"><a class="module-link" href="/sistema/?catalogo=1&amp;categoria=<?= h($key) ?>">Consultar serviços ↗</a><button type="button" class="audio" data-read="<?= h($module[0].'. '.$module[2].' Consulte orientações e fontes oficiais.') ?>" aria-label="Ouvir sobre <?= h($module[0]) ?>">◖ Ouvir</button></div></article>
  <?php endforeach; ?></div><p id="noResults" class="muted" hidden>Nenhum caminho encontrado. Tente documentos, estudos, cursos, trabalho ou apoio.</p>
  <section class="next-note"><span class="big-symbol" aria-hidden="true">↗</span><div><p class="kicker">UM PASSO DE CADA VEZ</p><h2>Seu recomeço tem espaço aqui.</h2><p>Consulte os serviços, confira as orientações e escolha seu próximo passo.</p></div></section>
  <form method="post" class="session-controls"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="clear"><p class="small">Suas escolhas e os caminhos guardados ficam nesta sessão do navegador.</p><button type="submit" class="quiet">Limpar escolhas e caminhos</button></form>
 </div>
<?php endif; ?>
<p id="voiceStatus" class="voice-status" role="status" aria-live="polite"></p>
</main><footer><div class="wrap"><a class="rf-brand brand footer-brand" href="/" aria-label="ReintegraFacil, página inicial"><img class="rf-logo" src="/assets/identidade/reintegrafacil.svg?v=20261108-1" width="340" height="88" alt="ReintegraFacil" decoding="async"></a><span>Informação para recomeçar. Um passo de cada vez.</span><nav class="app-footer-links" aria-label="Ajuda e informações"><a href="/pesquisa.html">Compartilhar minha opinião</a></nav></div></footer>
</body></html>
