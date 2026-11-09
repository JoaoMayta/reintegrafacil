# ReintegraFacil
reintegrafacil.online

Aplicativo web para consulta de orientações e fontes oficiais sobre documentos, estudos, cursos, trabalho e apoio social. Inclui catálogo, filtros territoriais, detalhes, caminhos guardados, leitura por áudio, seleção por voz, questionário e administração.

## Executar com Docker

Requer Docker Desktop ou Docker Engine com Compose. Na primeira execução, o computador precisa de internet para baixar e construir as imagens. Depois de extrair, abra o terminal na pasta que contém este README.

No Windows com Docker Desktop aberto, também é possível executar `iniciar_windows.bat`; ele gera a configuração local e mostra a senha administrativa. Pelo terminal:

1. Copie `.env.example` para `.env` e substitua as três senhas de exemplo por senhas locais diferentes. Como alternativa, se houver PHP instalado, execute `php scripts/preparar_ambiente.php` para gerar esse arquivo e as senhas automaticamente.
2. Execute `docker compose up --build -d`. Em instalações com Compose separado, use `docker-compose up --build -d`.
3. Abra `http://localhost:8080`. Sistema: `/sistema/`. Questionário: `/pesquisa.html`. Administração: `/admin/`, com a senha `ADMIN_PASSWORD` do `.env`.
4. Para encerrar, execute `docker compose down`. O volume do banco conserva as alterações.

O banco e os doze serviços iniciais são criados automaticamente. Repetir a instalação preserva os serviços cadastrados. A porta padrão fica disponível somente no próprio computador; altere `APP_PORT` se 8080 estiver ocupada.

## Executar com PHP e MariaDB instalados

Requer PHP 8.3 com PDO MySQL, mbstring, iconv, curl e sessões, além de MariaDB 11.4. Crie um banco vazio, copie `private/reintegra_pesquisa_config.example.php` para `private/reintegra_pesquisa_config.php` e preencha DSN, usuário e senha. Gere a senha administrativa com `password_hash` e configure seu hash. Execute `php scripts/instalar.php` e depois `php -S localhost:8080 -t public_html` na raiz do projeto. Em hospedagem, publique somente `public_html` e mantenha `private` fora da raiz pública.

## Busca e voz

A busca local funciona sem conta externa. Para habilitar a interpretação por IA, preencha `AI_API_KEY` no `.env` do Docker ou configure `private/busca_ia_config.php` a partir do exemplo na instalação manual. A chave e o modelo dependem da configuração do fornecedor. As respostas ficam restritas aos serviços publicados; falhas preservam a busca local.

Os áudios MP3 estão incluídos e não exigem instalação de um gerador de voz. A reprodução pode exigir toque inicial. O microfone depende de permissão e suporte do navegador; use localhost para a apresentação local e HTTPS em hospedagem. Texto e botões continuam disponíveis.

## Configurações e dados

Não publique `.env`, configurações reais, senhas, chaves ou respostas de usuários. Este pacote contém somente código, recursos e o catálogo inicial; dados do servidor original não fazem parte da distribuição. Os créditos e licenças dos recursos estão nas pastas de imagens, fontes e vozes.
