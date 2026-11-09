<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('Somente CLI.');
$path=dirname(__DIR__).'/.env';
if(is_file($path)){fwrite(STDERR,"O arquivo .env já existe; configuração preservada.\n");exit(1);}
$db=bin2hex(random_bytes(20));$root=bin2hex(random_bytes(24));$admin=bin2hex(random_bytes(10));
file_put_contents($path,"APP_PORT=8080\nDB_PASSWORD=$db\nDB_ROOT_PASSWORD=$root\nADMIN_PASSWORD=$admin\nAI_API_KEY=\nAI_MODEL=nvidia/nemotron-3.5-super-vl:free\n");
chmod($path,0600);echo "Ambiente preparado. Senha administrativa: $admin\nA senha também está no .env local; não publique esse arquivo.\n";
