<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('Somente CLI.');
$base=dirname(__DIR__).'/private/';$password=getenv('ADMIN_PASSWORD');
if(!$password||!getenv('DB_PASSWORD'))throw new RuntimeException('Prepare o arquivo .env antes de iniciar.');
$config=['database'=>['dsn'=>'mysql:host=db;dbname=reintegrafacil;charset=utf8mb4','username'=>'reintegrafacil','password'=>getenv('DB_PASSWORD')],'admin_password_hash'=>password_hash($password,PASSWORD_DEFAULT)];
file_put_contents($base.'reintegra_pesquisa_config.php',"<?php\nreturn ".var_export($config,true).";\n");
if(getenv('AI_API_KEY'))file_put_contents($base.'busca_ia_config.php',"<?php\nreturn ".var_export(['api_key'=>getenv('AI_API_KEY'),'model'=>getenv('AI_MODEL')?:'nvidia/nemotron-3.5-super-vl:free'],true).";\n");
require $base.'reintegra_pesquisa_lib.php';
for($i=0;$i<60;$i++){try{reintegra_pesquisa_db();exit(0);}catch(Throwable $e){sleep(1);}}
throw new RuntimeException('Banco não respondeu; confira a configuração do ambiente.');
