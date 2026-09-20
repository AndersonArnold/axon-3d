# Site da Axon 3D com painel administrativo (versão para hospedagem comum com PHP)

Não precisa de Netlify, GitHub nem banco de dados. Funciona em qualquer hospedagem compartilhada com **PHP 7.4 ou superior** (Hostinger, Locaweb, HostGator, Hostnet, Umbler, etc.).

## Instalação (uma vez só)
1. No painel da hospedagem, abra o **Gerenciador de Arquivos** e entre na pasta do site (normalmente `public_html`).
2. Envie o arquivo `axon3d-site-php.zip` e use **Extrair**. Os arquivos (`index.html`, `api.php`, `admin`...) devem ficar direto dentro de `public_html`. Se preferir, pode extrair em uma subpasta e o site funciona igual.
3. Abra o arquivo `config.php` (botão **Editar**) e troque `troque-esta-senha` por uma senha longa. É a senha do painel.
4. Ative o **SSL (HTTPS) gratuito** no painel da hospedagem, para a senha não trafegar aberta.
5. Teste abrindo `seudominio.com.br/api.php?r=health`. Deve aparecer `"passwordSet":true`.
6. Entre em `seudominio.com.br/admin/` com a senha.

Se os arquivos que começam com ponto (`.htaccess`) não aparecerem, ative "mostrar arquivos ocultos" no Gerenciador de Arquivos.

## Como usar o painel
- **Início:** título, botões e vídeo real do Funko (MP4 ou WebM) ou a animação da impressora.
- **Galeria:** crie projetos, envie várias fotos (a primeira é a capa), mude a ordem, esconda ou exclua. Os 9 projetos de exemplo com ilustração podem ser excluídos ou ocultados quando você tiver as fotos reais.
- **Depoimentos:** foto original do cliente + foto dele com o Funko.
- **Aparência:** cores. **Geral:** WhatsApp, redes, logo, menu e seções visíveis.
- **Ver prévia** abre o site com as mudanças ainda não publicadas. **Publicar** envia para o site.

## Limites
- O tamanho máximo de cada arquivo depende da hospedagem (até 25 MB). O painel mostra o limite na hora de enviar o vídeo. As fotos são reduzidas automaticamente e ficam bem pequenas.
- Para vídeo: 6 a 10 segundos, sem áudio, 720p. Exemplo com FFmpeg:
  `ffmpeg -i entrada.mp4 -t 8 -vf "scale=720:-2,fps=24" -an -c:v libx264 -crf 28 -preset slow -movflags +faststart saida.mp4`
- A sessão do painel dura 12 horas. Trocar a senha em `config.php` encerra todas as sessões.

## Backup
Tudo que você edita fica em duas pastas: `data` (textos) e `uploads` (fotos e vídeos). Baixe as duas de vez em quando. O painel também tem "Baixar backup" na aba **Backup e sistema**.

## Problemas
Abra `seudominio.com.br/api.php?r=health`:
- **Página 404:** o `api.php` não está na pasta do site. Confira onde os arquivos foram extraídos.
- **`"passwordSet":false`:** a senha em `config.php` não foi trocada (ou o arquivo tem erro de digitação).
- **`"dataWritable":false` ou `"uploadsWritable":false`:** dê permissão de escrita (755 ou 775) às pastas `data` e `uploads`.
- **Não aparece nada / erro 500:** confira se a hospedagem está com PHP 7.4 ou superior.

## Segurança
- Use uma senha longa e HTTPS.
- Em hospedagens que não usam Apache/LiteSpeed (só Nginx), os arquivos `.htaccess` não têm efeito. Nesse caso, peça ao suporte para bloquear o acesso direto à pasta `data`.
