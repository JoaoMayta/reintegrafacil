<?php
declare(strict_types=1);

/** Accent-insensitive matching over public catalogue content. No network or user storage. */
function rf_search_fold(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii === false ? $text : $ascii)));
}

function rf_search_has(string $text, string $pattern): bool
{
    return preg_match('/(?:^| )(?:' . $pattern . ')(?: |$)/', $text) === 1;
}

function rf_search_tokens(string $text): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = array_fill_keys(explode(' ', 'a ao aos as o os de da das do dos e em no nos na nas um uma uns umas para pra pro por com sem que qual quais como onde quando meu meus minha minhas eu me mim voce voces aqui agora hoje muito mais menos preciso precisa precisando quero queria gostaria estou esta ta to tenho tem ter ser pode posso poderia ajuda ajudar encontrar encontre achar buscar busca busco procura procurar procurando sobre saber informa informacao informacoes servico servicos por favor novamente sair sai saindo prisao preso detento cadeia egresso ex conseguir consigo arrumar obter fazer voltar ir ver'), true);
    }
    $tokens = array_values(array_unique(explode(' ', $text)));
    return array_values(array_filter($tokens, static fn(string $term): bool => $term !== '' && !isset($stop[$term]) && (strlen($term) >= 3 || $term === 'rg')));
}

/**
 * Receives only the published services permitted by the caller's filters.
 * It never adds candidates or changes regional/category restrictions.
 *
 * @return array{services:array,intent:string,understood:string}
 */
function rf_search_match(array $services, string $query): array
{
    $query = mb_substr(trim($query), 0, 300, 'UTF-8');
    $fold = rf_search_fold($query);
    if ($fold === '') {
        return ['services' => array_values($services), 'intent' => 'outro', 'understood' => ''];
    }

    // A negated topic should not outweigh a positive request later in the sentence.
    $intentText = (string) preg_replace('/\bnao (?:quero|preciso|procuro|busco) (?:de )?(?:emprego|trabalho|estudar|escola|curso|cursos|documentos)\b/', '', $fold);
    $patterns = [
        'trabalho' => 'emprego(?:s)?|empregabilidade|empregado|desempregad[oa]|desemprego|trabalh(?:o|ar|ando)|trampo|vagas?|curriculo|sine|cate|emprega brasil|mercado de trabalho|mao de obra',
        'documentos' => 'documento(?:s)?|documentacao|cpf|rg|cin|identidade|carteira de identidade|carteira de trabalho|ctps',
        'estudos' => 'estud(?:o|os|ar|ando)|escol(?:a|as)|escolar|ensino|eja|encceja|alfabetiz(?:acao|ar)|analfabet[oa]|educacao|fundamental|ensino medio|matricula|ler e escrever|nao sei ler|aprender a ler',
        'cursos' => 'curso(?:s)?|qualific(?:acao|ar)|capacit(?:acao|ar)|informatica|computador|habilidades digitais|profissionaliz(?:acao|ante)|aprender uma profissao',
        'apoio' => 'caps|psicossocial|psicolog[oa]|psicologic[oa]|saude mental|escritorio(?:s)? socia(?:l|is)|apoio|cras|cadunico|cadastro unico|assistencia social|assistente social|beneficio(?:s)?|auxilio|bolsa familia|moradia|abrigo|alimento(?:s)?|comida|fome|ajuda social',
    ];
    $active = [];
    foreach ($patterns as $key => $pattern) {
        if (rf_search_has($intentText, $pattern)) $active[$key] = true;
    }
    // Reintegration alone leads to public social orientation, never a claim of eligibility.
    if (!$active && rf_search_has($intentText, 'sair da prisao|sai da prisao|sair da cadeia|sai da cadeia|egresso|ex detento|detento|preso|reinsercao|reintegracao')) {
        $active['apoio'] = true;
    }
    // “Carteira de trabalho” denotes the actual document rather than job listings.
    $specific = [];
    foreach ([
        'cpf' => 'cpf|cadastro de pessoas fisicas',
        'cin' => 'rg|cin|identidade|carteira de identidade',
        'ctps' => 'ctps|carteira de trabalho',
        'eja' => 'eja',
        'encceja' => 'encceja',
        'sine' => 'sine|emprega brasil',
        'cate' => 'cate',
        'caps' => 'caps|psicossocial|psicolog[oa]|saude mental',
        'escritorio' => 'escritorio(?:s)? socia(?:l|is)',
        'cras' => 'cras',
        'cadunico' => 'cadunico|cadastro unico|bolsa familia',
    ] as $key => $pattern) {
        if (rf_search_has($intentText, $pattern)) $specific[] = $key;
    }
    $specificCategories = [];
    foreach ($specific as $alias) {
        $specificCategories[] = [
            'cpf'=>'documentos','cin'=>'documentos','ctps'=>'documentos',
            'eja'=>'estudos','encceja'=>'estudos','sine'=>'trabalho','cate'=>'trabalho',
            'cras'=>'apoio','caps'=>'apoio','escritorio'=>'apoio','cadunico'=>'apoio',
        ][$alias];
    }
    if (in_array('ctps', $specific, true) && count($specific) === 1 && !rf_search_has($intentText, 'emprego|vagas?|curriculo|trampo')) {
        unset($active['trabalho']);
    }
    // These requests name services absent from this catalogue. Generic category
    // synonyms must not turn them into a false positive for a different document.
    $unsupported = rf_search_has($intentText, 'cnh|passaporte|certidao|titulo de eleitor|alvara de soltura|progressao de regime|habeas corpus');
    if ($unsupported && !$specific) $active = [];

    $tokens = rf_search_tokens($intentText);
    if (!$active && !$specific && !$tokens) {
        return ['services' => [], 'intent' => 'outro', 'understood' => ''];
    }
    $matches = [];
    foreach ($services as $service) {
        if (!is_array($service)) continue;
        $title = rf_search_fold((string) ($service['servico'] ?? $service['titulo'] ?? ''));
        $description = rf_search_fold((string) ($service['descricao'] ?? ''));
        $steps = rf_search_fold((string) ($service['passos'] ?? ''));
        $category = (string) ($service['categoria'] ?? '');
        $score = 0;
        $specificHit = false;
        foreach ($specific as $alias) {
            $aliasPatterns = [
                'cpf' => 'cpf|cadastro de pessoas fisicas', 'cin' => 'cin|identidade',
                'ctps' => 'carteira de trabalho|ctps', 'eja' => 'eja', 'encceja' => 'encceja',
                'sine' => 'sine|emprega brasil', 'cate' => 'cate', 'cras' => 'cras',
                'caps' => 'caps|psicossocial', 'escritorio' => 'escritorio(?:s)? socia(?:l|is)',
                'cadunico' => 'cadastro unico|cadunico',
            ];
            if (rf_search_has($title, $aliasPatterns[$alias])) {
                $score += 120;
                $specificHit = true;
            }
        }
        $phraseTitle = strlen($fold) >= 3 && str_contains($title, $fold);
        if ($phraseTitle) $score += 70;
        if (strlen($fold) >= 4 && str_contains($description, $fold)) $score += 25;
        $tokenHits = 0;
        foreach ($tokens as $term) {
            $quoted = preg_quote($term, '/');
            if (rf_search_has($title, $quoted)) { $score += 14; ++$tokenHits; }
            elseif (rf_search_has($description, $quoted)) { $score += 6; ++$tokenHits; }
            elseif (rf_search_has($steps, $quoted)) { $score += 1; ++$tokenHits; }
        }
        $semantic = isset($active[$category]);
        if ($specific && !$specificHit) {
            // An explicit document/service request stays exact. A second topic
            // (e.g. CPF e emprego) can still include its own service category.
            $semantic = $semantic && !in_array($category, $specificCategories, true);
            if (!$semantic && !$phraseTitle) $score = 0;
        }
        if ($unsupported && !$specific) { $score = 0; $semantic = false; }
        if ($semantic) $score += 80;
        // A work document is useful after job-search services, not before them.
        if (isset($active['trabalho']) && !$specific && $category === 'documentos' && rf_search_has($title, 'carteira de trabalho')) $score += 35;
        $topicOnly = !array_diff($tokens, ['trabalho','emprego','empregos','vagas','vaga','trabalhar','trampo','apoio','social','assistencia','documento','documentos','documentacao','estudar','estudo','estudos','escola','ensino','curso','cursos','informatica','qualificacao']);
        if ($active && $topicOnly && !$specificHit && !$semantic && !(isset($active['trabalho']) && $category === 'documentos' && rf_search_has($title, 'carteira de trabalho'))) $score = 0;
        // Terms mentioned only incidentally in service steps should not fill a
        // catalogue for an unrelated query. Unknown multiword queries need a
        // majority of their meaningful words in the public title/description.
        if (!$semantic && !$specificHit && !$phraseTitle && !(isset($active['trabalho']) && $category === 'documentos' && rf_search_has($title, 'carteira de trabalho'))) {
            $required = max(1, (int) ceil(count($tokens) * 0.65));
            if ($score < 6 || $tokenHits < $required) $score = 0;
        }
        if ($score <= 0) continue;
        $service['_relevance'] = $score;
        $matches[] = $service;
    }
    usort($matches, static function (array $a, array $b): int {
        return ($b['_relevance'] <=> $a['_relevance']) ?: ((int) ($a['pk'] ?? $a['_pk'] ?? 0) <=> (int) ($b['pk'] ?? $b['_pk'] ?? 0));
    });
    $intent = count($active) === 1 ? (string) array_key_first($active) : 'outro';
    $understood = [
        'trabalho' => 'Você está procurando trabalho.',
        'documentos' => 'Você está procurando orientação sobre documentos.',
        'estudos' => 'Você está procurando um caminho para estudar.',
        'cursos' => 'Você está procurando cursos e qualificação.',
        'apoio' => 'Você está procurando apoio e orientação social.',
    ][$intent] ?? '';
    return ['services' => $matches, 'intent' => $intent, 'understood' => $understood];
}
