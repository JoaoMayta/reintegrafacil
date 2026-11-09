<?php
declare(strict_types=1);
require_once __DIR__.'/reintegra_pesquisa_lib.php';
function cat_db(): PDO { return reintegra_pesquisa_db(); }
function cat_h($s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function cat_statuses(): array { return ['rascunho'=>'Rascunho','publicado'=>'Publicado','em_revisao'=>'Em revisão','despublicado'=>'Despublicado']; }
function cat_modalidades(): array { return ['orientacao_online'=>'Orientação pela internet','presencial'=>'Atendimento presencial','hibrido'=>'Internet e atendimento presencial','nao_informada'=>'Consulte a instituição']; }
function cat_fields(): array { return ['servico'=>'Nome do serviço','responsavel'=>'Instituição responsável pelo serviço','descricao'=>'Descrição','url'=>'Fonte oficial (https://)','categoria'=>'Categoria','escopo'=>'Escopo territorial','uf'=>'UF (Todas para nacional)','cidade'=>'Município para filtro','abrangencia'=>'Abrangência apresentada','municipio'=>'Localidade e atendimento','modalidade'=>'Modalidade de acesso','publico'=>'Público indicado na fonte','condicoes'=>'Condições de acesso indicadas na fonte','endereco'=>'Endereço (se disponível)','telefone'=>'Contato (se disponível)','data_fonte'=>'Data informada na fonte (se houver)','data_verificacao'=>'Data real de conferência da fonte','proxima_revisao'=>'Próxima revisão prevista','passos'=>'Passo a passo (uma orientação por linha)']; }
function cat_categories(): array { return cat_db()->query('SELECT slug,nome FROM catalogo_categorias ORDER BY nome')->fetchAll(PDO::FETCH_KEY_PAIR); }
function cat_rows(bool $all=false): array { return cat_db()->query('SELECT id,codigo,dados,publicado,status_editorial,versao FROM catalogo_servicos'.($all?'':" WHERE publicado=1 AND status_editorial='publicado'").' ORDER BY id')->fetchAll(); }
function cat_new_code(): string { return 'RF-'.strtoupper(bin2hex(random_bytes(8))); }
/** Preserves fields supplied by earlier catalogues instead of dropping unedited provenance. */
function cat_merge_data(array $previous,array $fields,string $code,string $state,string $editor,string $reason): array {
    return array_replace($previous,$fields,['codigo'=>$code,'status_editorial'=>$state,'publicado'=>$state==='publicado'?1:0,'editorial_responsavel'=>$editor,'editorial_motivo'=>$reason,'editorial_atualizado_em'=>gmdate('c')]);
}
/** Caller must authorize the editor and check CSRF before invoking this private operation. */
function cat_save_service(PDO $db,array $input): int {
    $fields=[];
    foreach(cat_fields() as $key=>$label){$value=$input[$key]??'';if(!is_string($value))throw new RuntimeException('Confira '.$label.'.');$fields[$key]=trim($value);if(mb_strlen($fields[$key])>($key==='passos'?6000:2000))throw new RuntimeException('Campo longo demais: '.$label.'.');}
    $id=filter_var($input['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($id===false)throw new RuntimeException('Serviço inválido.');
    $state=$input['status_editorial']??(isset($input['publicado'])?'publicado':($id?'despublicado':'rascunho'));
    if(!is_string($state)||!isset(cat_statuses()[$state]))throw new RuntimeException('Situação editorial inválida.');
    if($fields['servico']===''||$fields['categoria']==='')throw new RuntimeException('Preencha o nome e a categoria do serviço.');
    if(!in_array($fields['escopo'],['nacional','estadual','municipal'],true))throw new RuntimeException('Escopo territorial inválido.');
    if($fields['modalidade']==='')$fields['modalidade']='nao_informada';
    if(!isset(cat_modalidades()[$fields['modalidade']]))throw new RuntimeException('Modalidade inválida.');
    if($state==='publicado')foreach(['responsavel','descricao','url','abrangencia','municipio','passos','data_verificacao','proxima_revisao'] as $key)if($fields[$key]==='')throw new RuntimeException('Para publicar, preencha '.cat_fields()[$key].'.');
    if($fields['url']!==''&&(!filter_var($fields['url'],FILTER_VALIDATE_URL)||parse_url($fields['url'],PHP_URL_SCHEME)!=='https'||parse_url($fields['url'],PHP_URL_USER)!==null))throw new RuntimeException('Informe uma fonte HTTPS válida, sem credenciais.');
    foreach(['data_verificacao','proxima_revisao'] as $key)if($fields[$key]!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$fields[$key]);if(!$d||$d->format('Y-m-d')!==$fields[$key])throw new RuntimeException('Data inválida.');}
    if($fields['data_verificacao']>date('Y-m-d')||($fields['proxima_revisao']!==''&&$fields['data_verificacao']!==''&&$fields['proxima_revisao']<$fields['data_verificacao']))throw new RuntimeException('Confira a sequência das datas.');
    if($fields['escopo']==='nacional'){$fields['uf']='Todas';$fields['cidade']='';}
    elseif(!preg_match('/^(AC|AL|AP|AM|BA|CE|DF|ES|GO|MA|MT|MS|MG|PA|PB|PR|PE|PI|RJ|RN|RS|RO|RR|SC|SP|SE|TO)$/',$fields['uf']))throw new RuntimeException('Informe a sigla de um estado.');
    if($fields['escopo']==='municipal'&&$fields['cidade']==='')throw new RuntimeException('Informe o município para filtro.');
    $editor=$input['editor']??'';$reason=$input['motivo']??'';
    if(!is_string($editor)||!is_string($reason)||trim($editor)===''||trim($reason)===''||mb_strlen($editor)>100||mb_strlen($reason)>500)throw new RuntimeException('Identifique o responsável editorial e o motivo da revisão.');
    $db->beginTransaction();
    try{
        $s=$db->prepare('SELECT slug FROM catalogo_categorias WHERE slug=? FOR UPDATE');$s->execute([$fields['categoria']]);if(!$s->fetchColumn())throw new RuntimeException('A categoria não existe mais. Escolha outra e tente novamente.');
        $previous=null;$before=[];$code=cat_new_code();
        if($id){
            $s=$db->prepare('SELECT codigo,dados,versao FROM catalogo_servicos WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch();if(!$old)throw new RuntimeException('Serviço não encontrado.');
            if(!is_scalar($input['versao']??null)||(int)($input['versao']??0)!==(int)$old['versao'])throw new RuntimeException('Outra edição foi salva. Reabra o serviço antes de alterar.');
            $previous=$old['dados'];$before=json_decode($previous,true,512,JSON_THROW_ON_ERROR);$code=$old['codigo']?:$code;
        }
        $record=cat_merge_data($before,$fields,$code,$state,trim($editor),trim($reason));$json=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$published=$state==='publicado'?1:0;
        if($id){$s=$db->prepare('UPDATE catalogo_servicos SET codigo=?,dados=?,publicado=?,status_editorial=?,versao=versao+1 WHERE id=?');$s->execute([$code,$json,$published,$state,$id]);}
        else{$s=$db->prepare('INSERT INTO catalogo_servicos(codigo,dados,publicado,status_editorial) VALUES(?,?,?,?)');$s->execute([$code,$json,$published,$state]);$id=(int)$db->lastInsertId();}
        $s=$db->prepare('INSERT INTO catalogo_revisoes(servico_id,responsavel,motivo,anterior,atual) VALUES(?,?,?,?,?)');$s->execute([$id,trim($editor),trim($reason),$previous,$json]);$db->commit();return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function cat_init(): void {
    $db=cat_db();
    $db->exec("CREATE TABLE IF NOT EXISTS catalogo_categorias (slug VARCHAR(40) PRIMARY KEY,nome VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS catalogo_servicos (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,codigo VARCHAR(30) UNIQUE,dados LONGTEXT NOT NULL,publicado TINYINT NOT NULL DEFAULT 1,status_editorial VARCHAR(20) NOT NULL DEFAULT 'publicado',versao INT NOT NULL DEFAULT 1,INDEX(status_editorial,publicado)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec('CREATE TABLE IF NOT EXISTS catalogo_revisoes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,servico_id BIGINT UNSIGNED NOT NULL,responsavel VARCHAR(100) NOT NULL,motivo VARCHAR(500) NOT NULL,anterior LONGTEXT,atual LONGTEXT NOT NULL,criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(servico_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $s=$db->prepare('INSERT IGNORE INTO catalogo_categorias VALUES (?,?)');foreach(['documentos'=>'Documentos','estudos'=>'Estudos','cursos'=>'Cursos','trabalho'=>'Trabalho','apoio'=>'Apoio'] as $k=>$v)$s->execute([$k,$v]);
    $rows=json_decode(file_get_contents(__DIR__.'/catalogo_fontes_iniciais.json'),true,512,JSON_THROW_ON_ERROR);$groups=['documentos','documentos','documentos','estudos','estudos','cursos','trabalho','trabalho','apoio','apoio'];
    $instructions=is_file(__DIR__.'/catalogo_orientacoes.json')?json_decode(file_get_contents(__DIR__.'/catalogo_orientacoes.json'),true,512,JSON_THROW_ON_ERROR):[];
    $insert=$db->prepare('INSERT IGNORE INTO catalogo_servicos(codigo,dados,publicado,status_editorial) VALUES(?,?,1,\'publicado\')');
    foreach($rows as $i=>$r){unset($r['situacao_no_reintegra']);$code=$r['id'];$r['categoria']=$groups[$i];$r['escopo']=$r['uf']==='Todas'?'nacional':(str_contains($r['abrangencia'],'Município')?'municipal':'estadual');$r['cidade']=$r['escopo']==='municipal'?'São Paulo':'';$r['proxima_revisao']='2026-12-07';$r['modalidade']='nao_informada';$r['publico']='';$r['condicoes']='';$r['passos']=isset($instructions[$code])?implode("\n",$instructions[$code]):'Consulte os requisitos e canais na fonte oficial antes de continuar.';$r=cat_merge_data($r,[],$code,'publicado','Equipe ReintegraFacil','Cadastro inicial baseado nas fontes documentadas.');$insert->execute([$code,json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
    $extras=__DIR__.'/catalogo_apoio_final_20261108.json';if(is_file($extras))foreach(json_decode(file_get_contents($extras),true,512,JSON_THROW_ON_ERROR) as $r){$insert->execute([$r['codigo'],json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);}
}
