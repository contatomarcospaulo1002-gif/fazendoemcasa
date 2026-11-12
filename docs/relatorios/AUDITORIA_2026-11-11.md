# Auditoria da landing `fazendoemcasa.com` — 11/11/2026

> **Produto:** guia em PDF "28 dias em casa" (14,90 €) + extra "28 Dias de Receitas" (7,90 €)
> **Checkout:** Kiwify (`pay.kiwify.com/5NJmQkR`)
> **Hospedagem:** GitHub Pages (repo **público** `contatomarcospaulo1002-gif/fazendoemcasa`), atrás do Cloudflare
> **Analisado em:** desktop (1024×768) e telemóvel (375×812)

---

## Resumo executivo

| # | Gargalo | Gravidade | Onde |
|---|---|---|---|
| **S1** | `.git/` exposto na web — histórico completo descarregável (137 arquivos) | 🔴 Crítica | `/.git/` |
| **S2** | Listagens de diretório abertas em 5 pastas | 🔴 Crítica | `/admin/api/`, `/docs/`, `/scripts/`, `/img/`, `/video/` |
| **S3** | `docs/INFRA_VPS.md` público: IP da VPS, porta SSH, utilizador `root`, caminhos | 🔴 Crítica | `/docs/INFRA_VPS.md` |
| **S4** | Login do painel sem rate-limit; `session.php` expõe a configuração | 🟠 Alta | `/admin/api/` |
| **S5** | Checkout vira **inglês** e fica **só cartão** quando o país é Portugal | 🔴 Crítica (comercial) | Kiwify |
| **S6** | Site com `noindex` + `robots.txt` a bloquear → **zero tráfego orgânico** | 🟠 Alta | `index.html:6` |
| **S7** | 3 carrosséis quebrados a cuspir erro em loop no console | 🟠 Alta | `carousel.js:149` |
| **S8** | Galerias "antes/depois" e "mensagens" com imagens desproporcionadas / placeholder à vista | 🟠 Alta | `index.html:551-599` |
| **S9** | Conteúdo raso para 24 ecrãs de telemóvel; sem credenciais de autor | 🟠 Alta | página inteira |
| **S10** | HTTP sem redirect para HTTPS, sem HSTS, sem headers de segurança | 🟡 Média | Cloudflare |
| **S11** | Imagens sem `srcset`, 13 pesadas sem `lazy`, sem favicon nem Open Graph | 🟡 Média | `index.html` |

**O texto da página é o melhor ativo do projeto.** Os problemas graves estão à volta dele: infraestrutura exposta (S1–S4) e checkout que perde o comprador português (S5).

---

# PARTE 1 — Segurança (fazer antes de qualquer marketing)

## S1 — `.git/` exposto publicamente 🔴

**Teste que confirma:**

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://fazendoemcasa.com/.git/HEAD
# 200
```

Todos estes respondem **200**:

```
/.git/HEAD          → ref: refs/heads/main
/.git/config        → configuração do repo
/.git/index         → índice (8714 bytes)
/.git/packed-refs
/.git/refs/heads/main
/.git/objects/      → listagem aberta
/.git/objects/pack/pack-081dc06f9536ef9968c40b93ba1f14a98a25623a.pack  → 331 KB
```

**O que isso significa:** qualquer pessoa descarrega o pack de 331 KB, roda `git checkout` e tem o repositório inteiro — histórico completo, branches, tudo. Confirmei que o pack é utilizável: descomprimi objetos reais (`commit`, `tree`, `blob`) e recuperei a lista de 137 arquivos, incluindo `admin/api/*.php`, `scripts/vps/deploy-via-ssh.ps1` e `docs/INFRA_VPS.md`.

**Atenuante:** o repositório do GitHub já é **público** (`"private": false`), então o código-fonte já era público. Também verifiquei que `admin/config.local.php` **nunca** foi commitado (`git log --all -- admin/config.local.php` vazio) e que o único arquivo de configuração no histórico é `config.example.php`, com os valores vindo de `getenv()` / `$db['pass']` vazio. **Não há credenciais no histórico exposto.**

**O risco real** não é o código — é: (a) pastas internas ficarem enumeráveis, (b) documentos operacionais vazarem (S3), (c) um repositório mais facilmente indexado por scanners de vulnerabilidade.

**Correção — passo a passo**

1. **Apagar a pasta `.git` do servidor de publicação.** GitHub Pages publica a *raiz do branch*. Duas formas:

   **A) Deploy por Actions (recomendado).** Publicar só os arquivos do site, nunca o `.git`:
   - Criar uma pasta `dist/` no build com `index.html`, `style.css`, `*.js`, `privacidade.html`, `termos.html`, `robots.txt`, `CNAME`, `.nojekyll`, `img/`, e **mais nada**.
   - Publicar essa pasta com `actions/upload-pages-artifact` + `actions/deploy-pages`.

   **B) Rápido, sem tocar no workflow:** mover `.git` para fora da raiz publicada não é possível no Pages. Se quiser manter o deploy atual, use a opção A — é a única que remove de fato o `.git` da web.

2. **Confirmar a remoção** (todos devem dar 404):

```bash
for p in .git/HEAD .git/config .git/index .git/refs/heads/main .git/objects/ .git/packed-refs; do
  echo -n "$p -> "; curl -s -o /dev/null -w "%{http_code}\n" "https://fazendoemcasa.com/$p"
done
```

3. **Se o repo ficar público,** tratar `docs/` como material público (ver S3).

> ⚠️ **Não** tente "esconder" com `robots.txt` — isso não impede o acesso, só pede aos motores de busca para não indexar. O arquivo continua descarregável.

---

## S2 — Listagens de diretório abertas 🔴

**Teste que confirma:**

```bash
curl -s https://fazendoemcasa.com/admin/api/ | head -3
# <title>Index of /admin/api</title>
```

**Pastas com listagem aberta:**

| Pasta | Conteúdo exposto |
|---|---|
| `/admin/api/` | `admin-users.php`, `apply-index.php`, `bootstrap.php`, `change-password.php`, `content.php`, `database.php`, `login.php`, `logout.php`, `profile.php`, `save-content.php`, `session.php`, `upload-image.php`, `upload-video.php` |
| `/docs/` | `INFRA_VPS.md`, `VPS-CMS-MAPEAMENTO.md` |
| `/scripts/` | `deploy.ps1`, `laragon-ativar-admin.ps1`, `vps/` |
| `/img/` | mapeamento completo de imagens internas |
| `/video/` | pasta de uploads |

**Bom:** os endpoints de escrita estão protegidos — testei `save-content.php`, `upload-image.php`, `upload-video.php`, `change-password.php` e todos devolvem **401** sem sessão. O `.htaccess` de `/admin/` e da raiz devolvem **403**. O problema é só a enumeração.

**Correção — passo a passo**

1. Adicionar ao `.htaccess` da **raiz** (crie se não existir) o bloqueio de listagem e das pastas internas:

```apache
Options -Indexes

# Nada de ferramentas de deploy ou documentação interna na web
RedirectMatch 404 ^/docs/
RedirectMatch 404 ^/scripts/
RedirectMatch 404 ^/docker/
RedirectMatch 404 ^/\.git
RedirectMatch 404 ^/\.github
RedirectMatch 404 ^/\.vscode
```

2. **Melhor ainda:** com o deploy do S1 (publicar só `dist/`), `docs/`, `scripts/`, `docker/`, `.github/` e `.vscode/` **nem chegam ao servidor**. Prefira esta via — é definitiva.

3. Confirmar:

```bash
for p in admin/api/ docs/ scripts/ img/ video/; do
  echo -n "$p -> "; curl -s "https://fazendoemcasa.com/$p" | grep -q "Index of" && echo "LISTAGEM ABERTA" || echo "ok"
done
```

---

## S3 — Documento de infraestrutura público 🔴

**Endereço:** `https://fazendoemcasa.com/docs/INFRA_VPS.md` (200, 3.657 bytes) — e também no GitHub em `raw.githubusercontent.com`.

**O que ele revela** (não vou reproduzir os valores aqui):

- O **IP da VPS** e a **porta SSH não padrão**
- que o utilizador SSH é **`root`**
- o caminho do site na VPS
- os nomes dos secrets do Actions: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_PATH`
- que a chave do GitHub Actions **é a mesma do Elba Food**
- o caminho `/home/elbasoftcom/elbafood.com.br`

Isso é um **mapa de ataque** entregue de graça: alvo, porta, utilizador privilegiado e a informação de que a mesma chave serve outro sistema.

**Correção — passo a passo**

1. **Tirar do repositório público** (o conteúdo é útil — só não pode ser público):

```bash
git rm --cached docs/INFRA_VPS.md docs/VPS-CMS-MAPEAMENTO.md
printf '\n# Documentos operacionais — não publicar\ndocs/INFRA_VPS.md\ndocs/VPS-CMS-MAPEAMENTO.md\n' >> .gitignore
git add .gitignore
git commit -m "Remove documentos de infraestrutura do repositório público"
```

2. Guardar o conteúdo fora do repo (gestor de senhas / pasta local), não num arquivo versionado.

3. **Rodar as chaves.** Como o IP, a porta e o utilizador de `root` estiveram públicos, e o doc diz que é a **mesma chave do Elba Food**:
   - gerar um novo par de chaves **exclusivo** para o `fazendoemcasa`
   - trocar o secret `DEPLOY_SSH_KEY` no GitHub
   - remover a chave antiga de `~/.ssh/authorized_keys` do servidor
   - se o mesmo par está no Elba Food, trocar lá também (o Elba Food é o sistema com dados de clientes — é o que mais importa)

4. Opcional mas recomendado: **parar de usar `root`** para deploy. Criar um utilizador `deploy@` com permissão só na pasta do site.

5. Limpar o histórico do GitHub (o arquivo continua acessível em commits antigos):

```bash
# opção simples: reescrever o histórico removendo o arquivo
git filter-repo --path docs/INFRA_VPS.md --path docs/VPS-CMS-MAPEAMENTO.md --invert-paths
git push --force-with-lease origin main   # ⚠️ só depois de rodar as chaves
```

> Se preferir não reescrever histórico agora, ao menos faça os passos 1 e 3 — rodar a chave resolve o risco real; o doc antigo vira apenas um registro histórico desatualizado.

---

## S4 — Painel administrativo 🟠

**Estado atual (testado):**

| Item | Resultado |
|---|---|
| `session.php` sem sessão | `{"ok":true,"authenticated":false,"php":true,"pinRequired":false,"accounts":true,"authMode":"account","database":true,"useDatabase":true,"profile":null}` |
| `save-content.php`, `upload-image.php`, `upload-video.php`, `change-password.php` | **401** ✅ |
| `admin-users.php` | 200 com corpo vazio (ok, não lista nada) |
| Login: 5 tentativas com PIN errado | 400 sempre — **sem rate-limit** |
| `login.php` (versão antiga no histórico) | PIN comparado com `hash_equals` ✅ |
| `/admin/content.json` | **200 sem login** (o conteúdo do site — aceitável, é texto público) |

**Riscos:**

1. **Sem rate-limit no login.** Testei 5 POSTs em sequência: todos devolvem 400, instantâneos, sem bloqueio nem atraso. Um PIN numérico curto cai por força bruta em minutos.
2. **`session.php` conta a arquitetura** a quem perguntar: se usa base de dados, se usa contas, o modo de autenticação. É reconhecimento de graça.
3. **`admin/content.json` é gravável?** Se o endpoint de upload aceitar arquivos com extensão livre, há risco de subir `.php`. Rodar `change-password.php` e `upload-image.php` com 401 já sugere que a autenticação funciona — mas vale testar o filtro de extensão (ver passo 4).

**Correção — passo a passo**

1. **Rate-limit no `admin/api/login.php`**: contar tentativas por IP numa tabela (`login_tentativas`: ip, tentativas, primeiro_ts, ultimo_ts) e recusar com **429** após 5 falhas em 15 minutos. Atenção: o projeto proíbe escrever em banco sem autorização — peça ao Marcos para rodar a migration.

2. **Reduzir a informação do `session.php`**: devolver só `{"ok":true,"authenticated":false}` quando não autenticado; manter o resto atrás do guard `admin_require_auth()`.

3. **Sessão segura** — no `bootstrap.php`:

```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
```

4. **Validar extensão nos uploads** de `upload-image.php` e `upload-video.php`: aceitar **somente** `webp|jpg|jpeg|png` e `mp4|webm`, conferir o MIME real com `finfo_file()`, renomear para nome gerado (`bin2hex(random_bytes(8))`) e descartar o nome original. Nunca permitir `.php`, `.phtml`, `.htaccess`, `.phar`.

5. **`admin/.htaccess`** já bloqueia (403) — manter.

6. Após as chaves do S3, revisar se o painel precisa mesmo estar em produção. Se a ideia é editar conteúdo localmente, desligue o painel no servidor e publique só o `dist/`.

---

# PARTE 2 — Checkout e conversão

## S5 — Checkout quebra para o comprador de Portugal 🔴 (o gargalo mais caro)

A landing fala português de Portugal, usa "€", cita o Livro de Reclamações e o DL 24/2014. **O checkout é uma loja brasileira.**

**Teste que confirma.** Abri `https://pay.kiwify.com/5NJmQkR` e troquei o país em "Alterar país" → Portugal:

| Antes (Brasil, padrão) | Depois (Portugal) |
|---|---|
| Botão: **`Pagar $14.11`** | Botão: **`Pay 14,90 €`** |
| Campo: **`CPF/CNPJ`** obrigatório | Campo some |
| Telefone: `+55` | Telefone: `+351` ✅ |
| Idioma: **português do Brasil** | Idioma: **inglês** |

Tradução literal do que apareceu depois de escolher Portugal:

```
"Change country"     "Name"     "Email"     "Confirm email"
"Card Number"    "Month"   "Year"    "CVC"
"Order details"  "Product"  "Price"  "Total"
"Pay 14,90 €"
"By clicking 'Pay 14,90 €', I declare that (i) I am aware that my purchase
 is being made from Kiwify, ..."
"Purchase Terms"   "Terms of Use"   "Privacy Policy"
"Report this product."
```

**Um comprador português que já converteu na página portuguesa chega ao checkout e encontra uma loja em inglês.** Isso, no momento de digitar o cartão, destrói a confiança construída pela landing.

### 5a — Falta MB WAY e Multibanco

Do payload do checkout:

```
ah[0]="credit_card"
availablePaymentMethods: ah
offeredPaymentMethods: ah
```

**Um único método: cartão.** Portugal paga de outros jeitos — MB WAY é o padrão de mercado, e Multibanco (referência) é essencial para quem não usa cartão online. Sem nenhum dos dois, a taxa de conclusão despenca.

### 5b — Apple Pay é prometido e não existe

`index.html:637`:

```html
<li>Pagamento por Kiwify (cartão ou Apple Pay)</li>
```

Na página de pagamento só há cartão. Verifiquei: nenhuma ocorrência de `Apple Pay`, `MB WAY`, `Multibanco`, `PayPal` ou `Google Pay` no DOM nem nos métodos aceitos.

### 5c — Nome do produto com HTML cru

No `<head>` do checkout:

```html
<title>28 dias em casa </title>
<meta property="og:title" content="28 dias em casa ">
```

E o texto interno traz markup colado:

```
28 dias em casa <link rel="stylesheet" href="https://fonts.googleapis.com/css2?...">
```

Alguém colou HTML no campo "nome do produto" do painel da Kiwify. Não aparece visualmente (é o `<title>`), mas quebra a pré-visualização ao partilhar o link e polui a página.

### 5d — Preço só corrige depois de trocar o país

O visitante brasileiro (padrão) vê `$14.11`; o português só vê `14,90 €` **depois** de lembrar de trocar o país. Se ninguém troca, compra achando que é dólar. O resumo de Portugal, quando carrega, está correto:

| Item | Valor |
|---|---|
| 28 dias em casa | 12,11 € |
| IVA | 2,79 € |
| **Total** | **14,90 €** |
| Extra: 28 Dias de Receitas | 6,42 € (+ IVA) = 7,90 € |

### Correção — passo a passo (na Kiwify)

1. **Painel Kiwify → Produto → Checkout / Configurações internacionais**
   - Definir **país padrão = Portugal** (ou detectar por IP/idioma do navegador).
   - Ativar **moeda EUR como principal**.
2. **Ativar os métodos de pagamento para Portugal**: MB WAY e Multibanco, além de cartão. Se a Kiwify não os oferecer na conta atual, avaliar Stripe/Paddle/Easypay como processador — vale medir antes de trocar tudo.
3. **Revisar o idioma do checkout internacional.** Se a Kiwify não permitir pt-PT, testar se o problema é o `Accept-Language` do navegador — o teste veio de um navegador `pt-BR` com fuso `America/Sao_Paulo`. **Repetir o teste com `Accept-Language: pt-PT` e fuso `Europe/Lisbon` para confirmar se o inglês é regra ou só efeito do meu ambiente.** Se for só efeito do ambiente, este ponto desce de 🔴 para 🟡 — mas 5a e 5b continuam valendo.
4. **Corrigir o nome do produto** no painel: remover o HTML colado, deixar só `28 dias em casa`.
5. **Alinhar a landing com a realidade** (`index.html:637`): trocar por `Pagamento por cartão` **enquanto** não houver Apple Pay. Só anuncie o que o checkout oferece.
6. **Teste de compra real** de Portugal (cartão português, sem MB WAY envolvido) e uma de MB WAY depois de ativo. Confirmar: e-mail de acesso chega, PDF abre, reembolso de 14 dias funciona.

> Não consegui concluir uma compra (não é meu papel gastar o dinheiro do Marcos). O que está provado é o **estado da página de pagamento** em cada país — idioma, método e valor. O fluxo pós-pagamento precisa do teste real.

---

## S6 — Zero tráfego orgânico (talvez intencional) 🟠

**Tres travas simultâneas:**

```
index.html:6          <meta name="robots" content="noindex, nofollow">
privacidade.html:6    <meta name="robots" content="noindex, nofollow">
termos.html:6         <meta name="robots" content="noindex, nofollow">

robots.txt:
User-agent: facebookexternalhit
Allow: /

User-agent: Facebot
Allow: /

User-agent: meta-externalagent
Allow: /

User-agent: *
Disallow: /
```

Além disso: **sem `canonical`, sem Open Graph, sem Twitter Card, sem `sitemap.xml` (404), sem favicon (`favicon.ico` 404), sem dados estruturados (`schema.org` = 0).**

O `robots.txt` abre exceção **só** para os robôs da Meta — ou seja, a intenção declarada é: *existo para o anúncio, não para o Google*.

**Decisão que o Marcos precisa tomar:**

- **Se for pago-para-sempre:** está coerente. Ainda assim, corrija o favicon (detalhe de confiança) e o Open Graph (o link partilhado no WhatsApp/Facebook aparece sem imagem — perde cliques na própria campanha paga).
- **Se quiser orgânico algum dia:** remova o `noindex`, libere o `robots.txt`, crie `sitemap.xml` e um H1/H2 com a intenção de busca real ("treino em casa para iniciantes PDF").

**Correção mínima (vale nos dois casos) — passo a passo**

1. **Favicon.** Gerar `favicon.ico` (32×32) + `apple-touch-icon.png` (180×180) e adicionar no `<head>`:

```html
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
```

2. **Open Graph** — sem isso, todo link partilhado no Facebook/Instagram/WhatsApp aparece sem imagem:

```html
<meta property="og:type"        content="product">
<meta property="og:title"       content="28 dias em casa — guia de treino em PDF">
<meta property="og:description" content="Plano de 4 semanas: 20 sessões, três níveis, sem pesos nem máquinas. Pagamento único.">
<meta property="og:image"       content="https://fazendoemcasa.com/img/og-cover.jpg">
<meta property="og:image:width"  content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url"         content="https://fazendoemcasa.com/">
<meta name="twitter:card"       content="summary_large_image">
<link rel="canonical" href="https://fazendoemcasa.com/">
```

   Criar `img/og-cover.jpg` 1200×630 com a capa do guia + título.

3. **Se quiser orgânico**, remover do `index.html` e das páginas legais:

```html
<meta name="robots" content="noindex, nofollow">
```

   E trocar o `robots.txt` por:

```txt
User-agent: *
Allow: /
Disallow: /admin/
Sitemap: https://fazendoemcasa.com/sitemap.xml
```

4. **`sitemap.xml`** com as 3 páginas (`/`, `/privacidade.html`, `/termos.html`).

5. **Dados estruturados** (aparece como resultado rico no Google):

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "28 dias em casa",
  "description": "Guia em PDF com plano de treino de 4 semanas, 20 sessões e três níveis.",
  "offers": {
    "@type": "Offer",
    "price": "14.90",
    "priceCurrency": "EUR",
    "availability": "https://schema.org/InStock",
    "url": "https://fazendoemcasa.com/"
  }
}
</script>
```

> **Atenção:** não coloque `aggregateRating` sem avaliações reais — é fraude e o Google penaliza.

---

## S7 — Três carrosséis quebrados 🟠

**Erro no console (repetido a cada ciclo do autoplay):**

```
Uncaught TypeError: Cannot read properties of undefined (reading 'getBoundingClientRect')
    at scrollSlideIntoView (carousel.js:16:24)
    at carousel.js:149:7
```

**Causa exata.** `carousel.js:149`:

```js
window.requestAnimationFrame(function () {
  scrollSlideIntoView(viewport, slides[0], "auto");   // ← slides[0] é undefined
  updateActive(root, viewport, slides);
  startAutoplay();
});
```

`slides` vem de `visibleSlides(track)`, que filtra os slides com `hidden`. Três carrosséis existem no HTML mas **todos os slides estão `hidden`** — e o próprio carrossel também:

```
index.html:186  #programa-media  → 6 slides, todos hidden, carrossel hidden
index.html:208  #paraquem-carousel → 6 slides, todos hidden, carrossel hidden
index.html:450  #receitas-carousel → 6 slides, todos hidden, carrossel hidden
```

Resultado: `slides` é `[]`, `slides[0]` é `undefined`, e `scrollSlideIntoView` chama `undefined.getBoundingClientRect()`.

Esses carrosséis foram criados para exibir imagens do CMS, mas **nenhuma imagem foi cadastrada** — e o `initCarousel` não verifica se há slides antes de animar.

**Correção — passo a passo**

1. **Uma linha resolve o erro** — em `carousel.js`, dentro de `initCarousel`, antes do `requestAnimationFrame`:

```js
    refreshSlides();
    root._carouselIndex = 0;
    if (!slides.length) { root._carouselRefresh = refreshSlides; return; }   // ← adicionar
    window.requestAnimationFrame(function () {
      scrollSlideIntoView(viewport, slides[0], "auto");
```

2. **Blindar a função** para nunca mais estourar:

```js
  function scrollSlideIntoView(viewport, slide, behavior) {
    if (!viewport || !slide) return;      // ← adicionar
    var vpRect = viewport.getBoundingClientRect();
```

3. **Reiniciar o autoplay quando o CMS preencher os slides.** `refreshSlides()` já é chamado pelo admin; como o `return` do passo 1 mantém `root._carouselRefresh`, quando chegarem imagens o carrossel passa a funcionar sozinho. Teste: cadastrar 2 imagens no painel e confirmar que o carrossel aparece e avança.

4. **Remover os 3 carrosséis se não forem ser usados.** Se não há plano de preencher, apagar o markup de `index.html:186-198`, `208-...` e `450-...` — menos 18 elementos `hidden` que nunca vão renderizar.

5. **Verificar:** recarregar a página e confirmar console limpo:

```js
// no console, após a correção:
// (nenhuma linha vermelha)
```

---

# PARTE 3 — Conteúdo e credibilidade

## S8 — Galerias com imagens erradas e placeholder à vista 🟠

### 8a — "Antes / Depois" fora de proporção

A secção "Algumas histórias no programa" (`index.html:551`) mostra **1 slide de 6** — os outros cinco estão vazios. E as duas imagens do slide visível têm proporções absurdamente diferentes:

| Imagem | Proporção real | Como aparece |
|---|---|---|
| `gallery.photos.s1.before.webp` | **1240 × 277** (panorâmica, 4,5:1) | **224 × 50** |
| `gallery.photos.s1.after.webp` | **448 × 391** (4:5) | **224 × 196** |

O CSS usa `grid-template-columns: 1fr 1fr` (`style.css:1967`) com `width:100%; height:auto` (`style.css:1979`). Como as duas têm a mesma largura de coluna mas alturas naturais diferentes, o "Antes" vira uma **faixa achatada** ao lado de um "Depois" vertical. Não lê como antes/depois — lê como layout quebrado.

**Correção:**

1. **Reenquadrar as fotos no mesmo aspecto** (3:4 ou 4:5, ambas). Antes e depois de treino têm de ter o mesmo enquadramento para comparar.
2. Ou **normalizar por CSS**, forçando a moldura:

```css
.ba-fig {
  aspect-ratio: 4 / 5;      /* ← adicionar: mesma moldura para as duas */
}
.ba-img {
  width: 100%;
  height: 100%;             /* ← era auto */
  object-fit: cover;        /* ← era contain */
}
```

3. Se não houver pares reais suficientes, **remover a secção** — 1 par sozinho com 5 slides vazios vende pior do que nenhum.

### 8b — Placeholder do painel visível no código

`index.html:587`:

```html
<img class="comment-img"
     src="img/gallery-demo/comentario-1.svg"
     alt="Exemplo de mensagem autorizada (substituir no painel admin)">
```

E o SVG servido diz literalmente:

```
Mensagem autorizada (exemplo)
```

Ou seja: a secção "O que dizem sobre o guia" exibe hoje um **cartão de exemplo**. Qualquer visitante que abra o inspetor — ou um leitor de ecrã — lê "substituir no painel admin".

Na renderização o `<img>` está com `naturalWidth = 0` (o SVG não carrega de fato), ocupando `458 × 520` de moldura vazia.

**Correção:**

1. **Remover a secção** enquanto não houver depoimentos reais e autorizados:

```html
<!-- apagar index.html:574-603 (secção #galeria-comentarios inteira) -->
```

2. Se for manter com dados reais: trocar o `alt` por um neutro (`"Mensagem de leitor autorizada"`), apagar `gallery-demo/` do build, e garantir que o CSS tem `min-height` só quando há imagem.

3. **Aplicar o mesmo critério a "Testemunhos"** (`index.html:493-541`): hoje ocupa **591 px de telemóvel** para dizer "ainda não há testemunhos". Honesto, mas caro — ocupa ¾ de ecrã a explicar a ausência. Sugestão: reduzir a uma linha discreta ou mover para o FAQ.

### 8c — Resumo das secções vazias

| Secção | Altura (telemóvel) | Conteúdo útil |
|---|---|---|
| `#depoimentos` | 895 px | texto "ainda não há" |
| `#galeria-fotos` | 688 px | 1 par (desproporcionado) |
| `#galeria-comentarios` | 853 px | 1 placeholder |

**~2.400 px (≈ 3 ecrãs)** que não vendem nada, entre o preço e a oferta final. Cada ecrã vazio é uma oportunidade de sair da página antes do CTA.

---

## S9 — Conteúdo: muito espaço, pouca informação 🟠

**Volume por secção** (contagem real de palavras):

| Secção | Palavras |
|---|---|
| Hero | 147 |
| O programa | 246 |
| Para quem é | 252 |
| Como funciona | 224 |
| O que está incluído | 169 |
| Receitas | 134 |
| Testemunhos | 36 |
| Galeria fotos | 57 |
| Galeria mensagens | 23 |
| Oferta | 118 |
| Garantia | 112 |
| FAQ | 325 |
| **Total** | **~1.843** |

**Densidade:** 19.586 px de altura ÷ 812 px de ecrã = **24 ecrãs** no telemóvel, para **1.843 palavras** → **≈ 76 palavras por ecrã**.

Para comparar: uma landing de infoproduto bem feita costuma ficar entre 1.500 e 3.000 palavras, mas distribuídas em **8 a 12 ecrãs**. Aqui o problema não é "pouco texto" nem "muito texto" — é **texto diluído em espaço**: cada bloco ocupa 2–3 ecrãs para entregar 3 ou 4 frases.

### O que falta de verdade

**1. Ninguém sabe quem escreveu.** Varri o HTML inteiro: **zero** menções a personal trainer, fisioterapeuta, CREF, formação, anos de experiência ou rosto do autor. Vendendo um guia de exercício, isso é o maior buraco de confiança da página — maior do que não ter testemunhos.

**2. Não há prova do produto além de 2 páginas.** Existem 2 imagens ampliáveis (páginas 8 e 13). Mostrar 3–4 páginas diferentes — o índice, uma sessão completa, o calendário preenchido — responde à dúvida real ("esse PDF tem substância?").

**3. O FAQ responde "Inclui vídeos? Não."** — mas existem slots de vídeo prontos e nunca usados:

```
index.html:82   #hero-video-block      (hidden)
index.html:301  #como-video-block      (hidden)
```

Um vídeo de 40 segundos mostrando uma sessão real seria a maior alavanca de conversão disponível — e a infraestrutura já está lá.

**4. Faltam respostas que o comprador português vai procurar:** "dá para fazer num apartamento pequeno?", "e se eu tiver dor no joelho?", "quanto tempo até ver diferença?", "serve para quem tem 50 anos?".

### Correção — passo a passo (por ordem de impacto)

1. **Bloco de autor** (1 ecrã): foto, nome, uma linha de credencial, por que fez o guia. Se não houver credencial formal, seja honesto — "não sou personal trainer, organizei o que funcionou para mim" é mais forte que o silêncio. **Não invente formação.**

2. **Amostra do PDF**: subir 2 páginas adicionais ampliáveis (índice + uma sessão da semana 2). Já existe o lightbox (`lightbox.js`) — é só adicionar `<button class="sample-zoom" data-lightbox-src="...">`.

3. **Vídeo curto** no `#hero-video-block` ou `#como-video-block`: 40–60 s, telemóvel na vertical, mostrando uma sessão. O `video-embed.js` já faz lazy-load com `IntersectionObserver`.

4. **Comprimir as secções vazias**: remover `#galeria-comentarios` e cortar `#depoimentos` para uma linha. Devolve ~2.000 px para conteúdo útil.

5. **Adicionar 6 perguntas** ao FAQ (já tem 10, todas boas) sobre: espaço pequeno, lesões, idade, tempo até resultado, o que fazer se falhar um dia, uso no telemóvel.

6. **CTA**: a cadência está boa (9 CTAs, o primeiro a 32 palavras do topo). Só falta o CTA **depois** das secções vazias — hoje quem passa por elas não encontra botão até `#oferta`.

---

# PARTE 4 — Técnico

## S10 — HTTPS e cabeçalhos 🟡

| Item | Estado |
|---|---|
| `http://fazendoemcasa.com/` | **200** — serve o site em claro, **sem redirect** |
| `Strict-Transport-Security` (HSTS) | **ausente** |
| `Content-Security-Policy` | **ausente** |
| `X-Content-Type-Options` | **ausente** |
| `Referrer-Policy` | **ausente** |
| `X-Frame-Options` | **ausente** |
| Compressão Brotli | ✅ ativa (HTML 52 KB → 11 KB) |
| Cache | `max-age=14400` (4 h) — baixo para assets versionados |

**Correção — passo a passo (Cloudflare)**

1. **SSL/TLS → Edge Certificates → Always Use HTTPS: ON.** Resolve o HTTP sem redirect na hora.
2. **HSTS: ON** com `max-age` de 6 meses para começar (⚠️ HSTS é difícil de desfazer — só ativar depois de confirmar que **todos** os subdomínios têm HTTPS válido).
3. **Rules → Transform Rules → Modify Response Header**, adicionar:

```
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
X-Frame-Options: SAMEORIGIN
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

4. **CSP** (começar em `Report-Only` para não quebrar o pixel nem o Kiwify):

```
Content-Security-Policy-Report-Only:
  default-src 'self';
  script-src 'self' https://connect.facebook.net;
  img-src 'self' data: https://www.facebook.com;
  style-src 'self';
  frame-src https://www.youtube.com https://player.vimeo.com;
  form-action https://pay.kiwify.com;
```

5. **Cache**: para `style.css?v=…`, `*.js?v=…` e `/img/*`, subir para `max-age=31536000, immutable`. O `?v=` já garante a invalidação.

6. **Página 404 própria.** Hoje devolve o erro cru do Apache (iso-8859-1, sem marca, sem link). No GitHub Pages basta criar `404.html` na raiz.

---

## S11 — Performance e imagens 🟡

| Item | Estado |
|---|---|
| Peso total | **~660 KB**, 30 requisições |
| Imagens | **549 KB** |
| `srcset` / `<picture>` | **0** — o mesmo arquivo serve telemóvel e desktop |
| `loading="lazy"` | 25 de 60 imagens |
| 13 imagens **eager** | 505 KB, incluindo secções lá em baixo |
| Hero | 1198×1313 (136 KB) exibida a 414×519 |
| Fontes | Sistema (`Segoe UI`) — ✅ ótimo, zero requisições |
| TTFB | **147 ms** ✅ |
| DOM interativo | **301 ms** ✅ |
| First Contentful Paint | 968 ms |
| `fetchpriority` | só em `guia-p08`; a **hero não tem** |

**Correção — passo a passo**

1. **`srcset` nas imagens grandes.** Exemplo para a hero:

```html
<img class="slot-photo"
     src="img/cms/hero.photo.hero-800.webp"
     srcset="img/cms/hero.photo.hero-480.webp 480w,
             img/cms/hero.photo.hero-800.webp 800w,
             img/cms/hero.photo.hero.webp 1198w"
     sizes="(max-width: 899px) 100vw, 45vw"
     width="1198" height="1313"
     alt="Treino em casa — agachamento ou prancha"
     fetchpriority="high">
```

   Gerar as 2 variantes a 480 e 800 px de largura, qualidade WebP ~72.

2. **`loading="lazy"`** nestas 13 imagens, que hoje carregam de imediato mesmo estando longe do topo:

```
img/cms/receitas.photo.webp          58 KB
img/cms/niveis.avancado.photo.webp   43 KB
img/cms/niveis.iniciante.photo.webp  41 KB
img/cms/programa.photo.webp          40 KB
img/cms/niveis.intermedio.photo.webp 35 KB
img/cms/programa.card1..6.webp       ~98 KB (6 arquivos, ~16 KB cada)
img/guia-p08.webp                    42 KB  ← manter eager, tem fetchpriority
```

   Acrescentar também `decoding="async"`.

3. **`width` e `height`** em todas as `<img>` — só **26 de 60** têm. Sem eles há deslocamento de layout (CLS) enquanto as imagens carregam.

4. **`alt` descritivo** nas 40 imagens com `alt=""`. Hoje estão vazias; isso mantém-se correto **só** para imagens puramente decorativas. As imagens dos níveis (iniciante/intermédio/avançado) e as do programa precisam de descrição real — ajuda SEO e leitura por ecrã.

5. **Verificar** com PageSpeed Insights após as mudanças, alvo: LCP < 2,5 s, CLS < 0,1.

---

# PARTE 5 — O que está bom (não estragar)

Registrado porque o texto é claramente o melhor ativo do projeto:

- **Registro pt-PT consistente** — "tens", "fazeres", "telemóvel" — sem escorregar para pt-BR.
- **Honestidade rara**: "não promete resultados", "para quem não é", "não é um plano alimentar", "preferimos deixá-lo vazio a inventar pessoas". Constrói confiança de um jeito que a maioria das páginas do gênero não consegue.
- **Compliance de verdade**: termos citando DL 24/2014 (livre resolução), DL 84/2021 (conformidade), RGPD art. 6/15–22; Livro de Reclamações Eletrónico; cláusula 6.2 sobre a exceção do conteúdo digital — texto correto e incomum.
- **Consentimento de cookies que funciona de fato**: `cookies.js` só carrega o pixel da Meta após "Aceitar", e ao recusar **apaga** `_fbp`/`_fbc` e trava o `fbq`. Acima da média portuguesa.
- **UTM propagado** para os 9 links de checkout via `utm.js`.
- **10 perguntas de FAQ**, todas respondidas e bem escritas.
- **CTA no hero visível** a 670 px (dentro da primeira dobra no telemóvel) + barra fixa que aparece após o hero (`sticky-cta.js`, 85 px, só ≤899 px).
- **Preço transparente**: 14,90 € IVA incluído, pagamento único, sem subscrição, extra 7,90 €, garantia 14 dias.
- **Carregamento rápido**: TTFB 147 ms, DOM interativo 301 ms, Cloudflare em frente.
- **Acessibilidade dos carrosséis**: `aria-roledescription`, `aria-label`, `tabindex`, `prefers-reduced-motion` respeitado.

---

# PARTE 6 — Ordem de execução sugerida

## Hoje (segurança — não depende de decisão de marketing)

| Ordem | Ação | Tempo |
|---|---|---|
| 1 | **Rodar a chave SSH** do deploy (S3) — ela está publicamente associada a `root` na VPS, com IP e porta expostos | 30 min |
| 2 | Remover `docs/INFRA_VPS.md` e `docs/VPS-CMS-MAPEAMENTO.md` do repo público (S3) | 10 min |
| 3 | Deploy por Actions publicando só `dist/` — mata S1 e S2 de uma vez | 1–2 h |
| 4 | `.htaccess` com `Options -Indexes` + `RedirectMatch 404` (S2) — se não fizer o item 3 agora | 15 min |
| 5 | `session.php` só devolver `{ok, authenticated}` (S4) | 20 min |
| 6 | Cookies de sessão `HttpOnly`/`Secure`/`SameSite` (S4) | 15 min |

## Esta semana (conversão)

| Ordem | Ação | Tempo |
|---|---|---|
| 7 | **Kiwify: país padrão Portugal + EUR + nome do produto sem HTML** (S5c/d) | 30 min |
| 8 | **Ativar MB WAY** e testar compra real (S5a) | 1–2 h + espera da Kiwify |
| 9 | Tirar "Apple Pay" da landing enquanto não existir (S5b) — `index.html:637` | 5 min |
| 10 | `if (!slides.length) return;` no `carousel.js` (S7) | 5 min |
| 11 | Remover as 2 galerias vazias + encurtar "Testemunhos" (S8) | 30 min |
| 12 | Favicon + Open Graph + `og-cover.jpg` (S6) | 45 min |

## Próximas duas semanas (conteúdo e técnico)

| Ordem | Ação | Tempo |
|---|---|---|
| 13 | **Bloco de autor** com foto e credencial (S9) | 2 h + decisão do Marcos |
| 14 | +2 páginas de amostra no lightbox (S9) | 30 min |
| 15 | Vídeo de 40 s nos slots já prontos (S9) | 1 h |
| 16 | 6 perguntas novas no FAQ (S9) | 45 min |
| 17 | Cloudflare: Always HTTPS + HSTS + headers (S10) | 30 min |
| 18 | `srcset` + `lazy` + `width/height` (S11) | 2 h |
| 19 | Página 404 com marca (S10) | 30 min |
| 20 | **Decisão sobre SEO** — manter `noindex` ou abrir para o Google (S6) | decisão |

---

# PARTE 7 — O que não consegui verificar

Sendo explícito sobre os limites desta auditoria:

1. **Não concluí uma compra real.** Não é papel meu gastar o dinheiro do Marcos. O que está provado é o estado da página de pagamento (idioma, método, valores por país). O fluxo pós-pagamento — e-mail de acesso, área de membros, PDF, reembolso — precisa de teste real.
2. **O inglês do checkout pode ser efeito do ambiente.** Meu navegador estava com `pt-BR` e fuso `America/Sao_Paulo`. É possível que um navegador com `Accept-Language: pt-PT` receba o checkout em português. Precisa ser retestado de Portugal (ou com o idioma do navegador em pt-PT).
3. **Não testei se o `admin/content.json` é gravável por upload.** Os endpoints de escrita devolvem 401, o que é bom sinal, mas o filtro de extensão em `upload-image.php`/`upload-video.php` só se confirma lendo o código ou testando com sessão.
4. **Não rodei PageSpeed Insights** (precisa de chave de API). As métricas vieram da Navigation Timing e Paint Timing do próprio navegador.
5. **Não avaliei o PDF em si** — só as duas páginas de amostra publicadas (8 e 13). A qualidade do conteúdo entregue é o fator que mais afeta reembolsos e não está no escopo do site.
6. **Não verifiquei o tráfego atual nem campanhas.** Se não há anúncio rodando, o `noindex` pode estar correto e "sem tráfego orgânico" não é gargalo — é escolha.

---

*Documento gerado a partir de navegação real (desktop + telemóvel), inspeção de HTML/CSS/JS servidos, requisições HTTP diretas, análise do pack do Git exposto e payload do checkout Kiwify, em 11/11/2026.*
