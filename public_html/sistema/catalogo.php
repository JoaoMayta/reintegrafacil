<?php
declare(strict_types=1);
if (!defined('CATALOGO_LAYOUT')) { header('Location: /sistema/?catalogo=1'); exit; }
require_once dirname(__DIR__, 2).'/private/catalogo.php';
require_once dirname(__DIR__, 2).'/private/busca_intencao.php';
require_once dirname(__DIR__, 2).'/private/busca_ia.php';
function cat_arg(string $key): string { return isset($_GET[$key]) && is_string($_GET[$key]) ? mb_substr(trim($_GET[$key]), 0, 100) : ''; }
function cat_fold(string $text): string { return mb_strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($text))); }
function cat_icon(string $key): string {
    $paths = [
        'documentos'=>'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'estudos'=>'<path d="M12 6c-3-2-6-2-9-1v14c3-1 6-1 9 1 3-2 6-2 9-1V5c-3-1-6-1-9 1v14"/>',
        'cursos'=>'<path d="m3 8 9-5 9 5-9 5-9-5Zm4 3v6c3 3 7 3 10 0v-6M21 8v9"/>',
        'trabalho'=>'<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V4h8v3M3 12c6 4 12 4 18 0M12 12v4"/>',
        'apoio'=>'<path d="M20 5c-3-3-6-1-8 1-2-2-5-4-8-1-5 5 2 11 8 15 6-4 13-10 8-15Z"/>'
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$key] ?? $paths['documentos']).'</svg>';
}
$allCategories=cat_categories();$cats=[];
foreach(['documentos','estudos','cursos','trabalho','apoio'] as $key)if(isset($allCategories[$key]))$cats[$key]=$allCategories[$key];
$cats += $allCategories;
$q = cat_arg('q'); $category = cat_arg('categoria');
$onlySaved = cat_arg('salvos') === '1';
$uf = array_key_exists('uf', $_GET) ? cat_arg('uf') : ($onlySaved?'':($draft['state'] ?? ''));
$city = array_key_exists('cidade', $_GET) ? cat_arg('cidade') : ($onlySaved?'':($draft['city'] ?? ''));
$context = ['catalogo'=>'1', 'q'=>$q, 'categoria'=>$category, 'uf'=>$uf, 'cidade'=>$city];
if ($onlySaved) $context['salvos'] = '1';
function cat_url(array $changes = []): string { global $context; return '/sistema/?'.http_build_query(array_replace($context, $changes)); }
function cat_date(string $date): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $d ? $d->format('d/m/Y') : $date; }
function cat_save_button(array $service, string $return, bool $compact = false): void {
    $saved = in_array((int)$service['pk'], $_SESSION['caminhos'] ?? [], true);
    ?><form method="post" class="save-form"><input type="hidden" name="csrf" value="<?=cat_h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="bookmark"><input type="hidden" name="service_id" value="<?=(int)$service['pk']?>"><input type="hidden" name="return_query" value="<?=cat_h(parse_url($return, PHP_URL_QUERY) ?: '')?>"><button class="save-button <?=$saved?'is-saved':''?>" type="submit" aria-label="<?=cat_h(($saved?'Retirar ':'Guardar ').$service['servico'].($saved?' dos':' em').' Meus caminhos')?>"><svg viewBox="0 0 24 24" fill="<?=$saved?'currentColor':'none'?>" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 3h12v18l-6-4-6 4V3Z"/></svg><span><?=$saved?'Guardado':($compact?'Guardar':'Guardar este caminho')?></span></button></form><?php
}
$services=[]; $counts=array_fill_keys(array_keys($cats),0); $detail=null;
foreach (cat_rows() as $row) {
    $r=json_decode($row['dados'], true); $r['pk']=(int)$row['id']; $r['codigo']=$row['codigo']; $services[]=$r;
    $counts[$r['categoria']]=($counts[$r['categoria']]??0)+1;
    if (cat_arg('servico') === (string)$row['id']) $detail=$r;
}
$eligible=[];
foreach ($services as $r) {
    if ($onlySaved && !in_array($r['pk'], $_SESSION['caminhos'] ?? [], true)) continue;
    if ($category !== '' && $r['categoria'] !== $category) continue;
    if ($uf !== '' && $r['escopo'] !== 'nacional' && $r['uf'] !== $uf) continue;
    if ($city !== '' && $uf === '' && $r['escopo'] !== 'nacional') continue;
    if ($city !== '' && $r['escopo'] === 'municipal' && cat_fold($r['cidade']) !== cat_fold($city)) continue;
    $eligible[]=$r;
}
$search=rf_search_match($eligible,$q);
$results=$search['services'];
$ai=['status'=>'skipped','ids'=>[],'intent'=>'outro','needs_clarification'=>false];
// A IA recebe apenas serviços já autorizados pelos filtros; dados de sessão não são enviados.
if ($q!=='' && !isset($_GET['servico']) && $eligible) {
    $ai=rf_ai_search($q,$eligible,['categoria'=>$category]);
    if (in_array($ai['status'],['ok','cached'],true) && !$ai['needs_clarification']) {
        $byId=array_column($eligible,null,'pk');$present=array_fill_keys(array_column($results,'pk'),true);
        foreach ($ai['ids'] as $id) {
            if (isset($byId[$id]) && !isset($present[$id])) {
                // Uma intenção clara local mantém prioridade. A IA pode ampliar pedidos não reconhecidos.
                if ($search['intent']!=='outro' && $ai['intent']!==$search['intent']) continue;
                $results[]=$byId[$id];$present[$id]=true;
            }
        }
        if ($search['intent']==='outro') $search['intent']=$ai['intent'];
    }
}
$intentMessages=[
    'trabalho'=>'Você procura trabalho. Estes caminhos ajudam a começar.',
    'documentos'=>'Você precisa de documentos. Veja os caminhos disponíveis.',
    'estudos'=>'Você quer voltar a estudar. Veja por onde começar.',
    'cursos'=>'Você procura um curso. Veja as opções do catálogo.',
    'apoio'=>'Você procura apoio. Veja os serviços que podem orientar você.',
];
$understanding=$q!=='' ? ($intentMessages[$search['intent']]??'') : '';
if (!$results && $understanding!=='') $understanding=explode('.',$understanding,2)[0].'.';
$hasFilters=$q!=='' || $category!=='' || $uf!=='' || $city!=='';
?>
<section class="wrap experience" id="catalogo">
<?php if (!empty($_SESSION['catalog_notice'])): ?><p class="inline-notice" role="status"><?=cat_h($_SESSION['catalog_notice'])?></p><?php unset($_SESSION['catalog_notice']); endif; ?>
<?php if (isset($_GET['servico'])): ?>
<nav class="breadcrumbs" aria-label="Caminho de navegação"><a href="<?=cat_h(cat_url(['servico'=>null]))?>">← Voltar aos resultados</a><?php if ($detail): ?><span aria-hidden="true">/</span><span><?=cat_h($cats[$detail['categoria']]??'Serviço')?></span><?php endif; ?></nav>
<?php if (!$detail): ?><div class="empty-state"><h1>Este caminho não foi encontrado.</h1><p>Ele pode ter sido retirado para atualização. Escolha outro serviço no catálogo.</p><a class="button" href="/sistema/?catalogo=1">Encontrar serviços</a></div><?php else: $r=$detail; $detailUrl=cat_url(['servico'=>$r['pk']]); ?>
<div class="detail-layout">
<article class="service catalog-detail" data-service-id="<?=$r['pk']?>">
    <div class="service-eyebrow"><span class="category-symbol tone-<?=cat_h($r['categoria'])?>"><?=cat_icon($r['categoria'])?></span><span><?=cat_h($cats[$r['categoria']]??'Serviço')?></span></div>
    <h1><?=cat_h($r['servico'])?></h1><p class="detail-intro"><?=cat_h($r['descricao'])?></p>
    <div class="detail-tools"><button type="button" class="audio" data-read="<?=cat_h($r['servico'].'. '.$r['descricao'].'. '.$r['passos'])?>">◖ Ouvir orientação</button><?php cat_save_button($r,$detailUrl); ?><button type="button" class="quiet print-guide">Imprimir</button></div>
    <section class="steps-section" aria-labelledby="steps-title"><p class="kicker">UM PASSO DE CADA VEZ</p><h2 id="steps-title">Como seguir por aqui</h2><ol class="reading-steps"><?php foreach (array_values(array_filter(explode("\n",$r['passos']),'trim')) as $i=>$step): ?><li class="reading-step"><span class="step-number" aria-hidden="true"><?=str_pad((string)($i+1),2,'0',STR_PAD_LEFT)?></span><p><?=cat_h($step)?></p></li><?php endforeach; ?></ol></section>
    <div class="source-note"><span aria-hidden="true">↗</span><p>Você está lendo uma orientação. O atendimento acontece com <strong><?=cat_h($r['responsavel'])?></strong>, pelo canal oficial indicado ao lado.</p></div>
    <details class="source-details"><summary>Fonte, localidade e datas de revisão</summary><dl><?php foreach (['Código do serviço'=>'codigo','Instituição responsável'=>'responsavel','Público indicado'=>'publico','Condições de acesso'=>'condicoes','Modalidade'=>'modalidade','Abrangência'=>'abrangencia','Estado'=>'uf','Localidade e atendimento'=>'municipio','Endereço'=>'endereco','Contato'=>'telefone','Data informada na fonte'=>'data_fonte','Conferido em'=>'data_verificacao','Próxima revisão'=>'proxima_revisao','Responsável editorial'=>'editorial_responsavel'] as $label=>$key): ?><dt><?=cat_h($label)?></dt><dd><?=cat_h(empty($r[$key])?'Consulte a instituição na fonte oficial':($key==='modalidade'?(cat_modalidades()[$r[$key]]??'Consulte a instituição'):(in_array($key,['data_verificacao','proxima_revisao'])?cat_date($r[$key]):$r[$key])))?></dd><?php endforeach; ?></dl><a href="<?=cat_h($r['url'])?>" target="_blank" rel="noopener noreferrer"><?=cat_h(parse_url($r['url'],PHP_URL_HOST))?> ↗</a></details>
</article>
<aside class="next-step-card" aria-label="Acesso ao serviço"><span class="small-label">SEU PRÓXIMO PASSO</span><h2>Vamos à fonte?</h2><p>Confira as condições, os documentos e os canais de atendimento diretamente com a instituição.</p><a class="button" href="<?=cat_h($r['url'])?>" target="_blank" rel="noopener noreferrer" aria-describedby="officialLinkHint">Acessar fonte oficial ↗</a><small id="officialLinkHint">Abre em outra aba · <?=cat_h(parse_url($r['url'],PHP_URL_HOST))?></small><hr><p class="coverage-label"><span aria-hidden="true">⌖</span> <?=cat_h($r['abrangencia'])?></p><p class="review-label">Conferido em <?=cat_h(cat_date($r['data_verificacao']))?></p><?php if ($r['proxima_revisao']<date('Y-m-d')): ?><p class="inline-notice">A revisão editorial está prevista para atualização. Confirme as condições no site oficial.</p><?php endif; ?><p class="small">Vagas, inscrições e benefícios seguem as regras de cada serviço.</p></aside>
</div>
<section class="help-strip"><div><h2>Quer encontrar outro caminho?</h2><p>Você pode voltar à busca com suas escolhas preservadas.</p></div><a class="quiet" href="<?=cat_h(cat_url(['servico'=>null]))?>">Voltar aos resultados →</a></section>
<?php endif; ?>
<?php else: ?>
<div class="catalog-intro"><div><p class="kicker"><?=$onlySaved?'SEUS CAMINHOS, NO SEU TEMPO':'BEM-VINDO AO REINTEGRAFACIL'?></p><h1><?=$onlySaved?'Seus caminhos<br>guardados.':'O que você<br>precisa hoje?'?></h1><p class="lead"><?=$onlySaved?'Seus serviços ficam aqui durante esta sessão.':'Documentos, estudos, cursos, trabalho e apoio.'?></p></div></div>

<form method="get" class="catalog-tools" id="serviceSearch"><input type="hidden" name="catalogo" value="1"><?php if($onlySaved): ?><input type="hidden" name="salvos" value="1"><?php endif; ?>
    <div class="search-primary"><label for="catalogSearch">Escreva ou fale o que procura.</label><div class="search-input-row"><input id="catalogSearch" name="q" type="search" value="<?=cat_h($q)?>" maxlength="100" placeholder="Ex.: quero trabalhar" autocomplete="off" aria-describedby="searchPrivacy"><button class="audio talk-search" type="button" data-dictate="catalogSearch" aria-label="Falar o que quero buscar" aria-describedby="voicePrivacy" aria-controls="catalogSearch" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="8" y="3" width="8" height="12" rx="4"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M9 21h6"/></svg> Falar</button><button class="button" type="submit">Buscar <span aria-hidden="true">→</span></button></div></div>
    <p id="searchPrivacy" class="voice-privacy">A busca usa IA para entender seu pedido e procurar no catálogo. Não informe CPF, telefone ou detalhes pessoais.</p>
    <p id="voicePrivacy" class="voice-privacy">Falar depende do navegador e pode usar um serviço externo. Você também pode escrever.</p>
    <details class="filter-details" <?=$category!==''||$uf!==''||$city!==''?'open':''?>><summary>Filtrar por assunto e cidade <span aria-hidden="true">+</span></summary><div class="location-fields"><label>Assunto<select name="categoria"><option value="">Todos os assuntos</option><?php foreach($cats as $k=>$v): ?><option value="<?=cat_h($k)?>" <?=$category===$k?'selected':''?>><?=cat_h($v)?></option><?php endforeach; ?></select></label><label>Estado<select name="uf" id="catalogState"><option value="">Todos os estados</option><?php foreach($states as $k=>$v): ?><option value="<?=cat_h($k)?>" <?=$uf===$k?'selected':''?>><?=cat_h($v)?> (<?=cat_h($k)?>)</option><?php endforeach; ?></select></label><label>Cidade<input name="cidade" id="catalogCity" value="<?=cat_h($city)?>" maxlength="100" placeholder="Ex.: São Paulo" aria-describedby="cityHint" autocomplete="address-level2"></label></div><p id="cityHint" class="small">Para usar a cidade, escolha também o estado. Serviços nacionais continuam incluídos.</p><button class="quiet apply-filters" type="submit">Aplicar filtros →</button></details>
    <?php if($hasFilters): ?><a class="clear-filters" href="<?=cat_h(cat_url(['q'=>'','categoria'=>'','uf'=>'','cidade'=>'']))?>">Limpar filtros</a><?php endif; ?>
</form><?php if (!$onlySaved): ?><nav class="category-paths" aria-label="Escolha um assunto"><?php foreach ($cats as $key=>$label): ?><a class="category-path <?=$category===$key?'selected':''?>" href="<?=cat_h(cat_url(['categoria'=>$category===$key?'':$key]))?>" <?=$category===$key?'aria-current="true"':''?>><span class="category-symbol tone-<?=cat_h($key)?>"><?=cat_icon($key)?></span><span><?=cat_h($label)?><small><?=$counts[$key]??0?> <?=($counts[$key]??0)===1?'serviço':'serviços'?></small></span><span class="path-arrow" aria-hidden="true">↗</span></a><?php endforeach; ?></nav><?php endif; ?>
<?php if($city!==''&&$uf===''): ?><p class="inline-notice" role="status">Escolha o estado de <?=cat_h($city)?> para incluir os serviços regionais. Por enquanto, mostramos somente serviços nacionais.</p><?php endif; ?>
<div class="results-heading" id="resultados" data-result-count="<?=count($results)?>" data-search-active="<?=$hasFilters||$onlySaved?'1':'0'?>"><div><p class="kicker"><?=$onlySaved?'PARA CONSULTAR QUANDO PRECISAR':'CAMINHOS DISPONÍVEIS'?></p><h2><?=$onlySaved?'Meus caminhos':($category!==''?cat_h($cats[$category]??'Resultados da busca'):'Encontre o que faz sentido agora')?></h2></div><p role="status"><?=count($results)?> <?=count($results)===1?'serviço encontrado':'serviços encontrados'?></p></div>
<?php if($hasFilters): ?><p class="filter-summary"><?php if($q!==''): ?>Buscando por <strong>“<?=cat_h($q)?>”</strong>. <?php endif; ?><?php if($uf!==''): ?><?=cat_h($city!==''?$city.' · '.$uf:($states[$uf]??$uf))?> · <?php endif; ?>Resultados respeitam a abrangência de cada serviço.</p><?php endif; ?>
<?php if($understanding): ?><p class="search-understanding"><?=cat_h($understanding)?></p><?php endif; ?>
<?php if($q!=='' && in_array($ai['status'],['unavailable','limited'],true)): ?><p class="search-availability">A ajuda de IA está indisponível agora. A busca do catálogo continua funcionando.</p><?php endif; ?>
<button type="button" class="audio read-results">◖ Ouvir resultados</button>
<section class="voice-choice" aria-label="Escolher um serviço por voz"><p id="voiceChoiceHint">Diga o nome ou o número de um serviço da lista. Se precisar de outra coisa, faço uma nova busca mantendo a região.</p><button type="button" class="audio" id="voiceChoice" data-conversation aria-pressed="false" aria-describedby="voiceChoiceHint voicePrivacy">Responder por voz</button></section>
<div class="catalog-list"><?php foreach($results as $resultIndex=>$r): ?><article class="service" data-service-id="<?=$r['pk']?>" data-category="<?=cat_h($r['categoria'])?>"><div class="card-topline"><span class="category-symbol tone-<?=cat_h($r['categoria'])?>"><?=cat_icon($r['categoria'])?></span><?php cat_save_button($r,cat_url(),true); ?></div><p class="service-category"><?=cat_h($cats[$r['categoria']]??'Serviço')?></p><h2><span class="voice-option-number">Opção <?=$resultIndex+1?> · </span><a href="<?=cat_h(cat_url(['servico'=>$r['pk']]))?>"><?=cat_h($r['servico'])?></a></h2><p class="service-description"><?=cat_h($r['descricao'])?></p><p class="service-coverage"><span aria-hidden="true">⌖</span> <?=cat_h($r['abrangencia'])?></p><div class="card-actions"><button type="button" class="audio read-summary" data-read="<?=cat_h($r['servico'].'. '.$r['descricao'])?>" aria-label="<?=cat_h('Ouvir resumo: '.$r['servico'])?>">◖ Ouvir resumo</button><a class="module-link" href="<?=cat_h(cat_url(['servico'=>$r['pk']]))?>" aria-label="<?=cat_h('Ver orientação: '.$r['servico'])?>">Ver orientação <span aria-hidden="true">→</span></a></div><p class="card-review">Fonte oficial · Conferido em <?=cat_h(cat_date($r['data_verificacao']))?></p></article><?php endforeach; ?></div>
<?php if(!$results): ?><section class="empty-state"><span class="empty-illustration" aria-hidden="true"><?=cat_icon('estudos')?></span><h2><?=$onlySaved&&!$hasFilters?'Guarde um caminho para voltar depois.':'Vamos tentar de outro jeito?'?></h2><p><?=$onlySaved&&!$hasFilters?'Ao encontrar um serviço, toque em Guardar. Ele aparece aqui enquanto sua sessão estiver ativa.':'Não encontrei serviços no catálogo com essas escolhas. Você pode mudar o pedido, retirar um filtro ou ver todos os serviços. Isso não significa que não exista atendimento na sua região.'?></p><a class="button" href="/sistema/?catalogo=1&amp;uf=&amp;cidade=">Ver todos os serviços →</a></section><?php endif; ?>
<section class="help-strip"><div><p class="kicker">CADA PESSOA TEM SEU TEMPO</p><h2>Precisa de uma mão para começar?</h2><p>As orientações aparecem na tela e podem ser ouvidas.</p></div><button type="button" class="button secondary replay-guide">Ouvir como usar ↗</button></section>
<?php if($onlySaved): ?><p class="session-explanation">Seus caminhos ficam apenas nesta sessão do navegador. Não criamos uma conta nem um cadastro pessoal para guardar os serviços.</p><?php if(!empty($_SESSION['caminhos'])): ?><form method="post" class="session-controls"><input type="hidden" name="csrf" value="<?=cat_h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="clear_paths"><button type="submit" class="quiet">Limpar caminhos guardados nesta sessão</button></form><?php endif; ?><?php endif; ?>
<?php endif; ?>
</section>
