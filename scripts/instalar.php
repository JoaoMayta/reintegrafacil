<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('Somente CLI.');
require dirname(__DIR__).'/private/catalogo.php';
reintegra_pesquisa_ensure_schema(cat_db());
reintegra_pesquisa_ensure_submissions_schema(cat_db());
cat_init();
echo 'Instalação concluída.'.PHP_EOL;
