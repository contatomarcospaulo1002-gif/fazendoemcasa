# Decisões de arquitetura — Central CMS multi-projeto

Registro append-only. Cada decisão tem data, contexto, escolha e
consequência. Não reescrever entradas antigas; adicionar novas.

---

## D-001 · Painel novo em `admin/cms/`, legado congelado
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** `admin.js` tem 2.466 linhas e o motor de edição é
específico do `index.html` da raiz. Reescrever no lugar arrisca quebrar a
única página que hoje funciona em produção.

**Decisão:** construir o multi-tenant em `admin/cms/` (namespace
`FazendoEmCasa\Cms`). O editor atual é renomeado para
`admin/legacy-editor.html` e continua servindo `index.html` da raiz até a
FASE 6 provar paridade.

**Consequência:** convivência temporária de dois editores. Custo aceito
para não interromper a produção.

---

## D-002 · Tabelas novas com prefixo `cms_` e `project_id`
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** `cms_content` (`id=1`), `cms_revisions`, `cms_media` já
existem e funcionam. Alterá-las quebraria o editor legado.

**Decisão:** criar tabelas **novas** com `project_id` obrigatório
(`cms_projects`, `cms_pages`, `cms_elements`, …). As três tabelas
existentes ficam intocadas.

**Consequência:** duplicação parcial de conceito (`cms_media` antiga vs
nova). Aceitável: a antiga é log de upload, a nova é biblioteca com
metadados.

**Alternativa rejeitada:** adicionar `project_id DEFAULT 1` nas tabelas
existentes. Rejeitada porque muda semântica de dados em produção sem
necessidade.

---

## D-003 · `token_get_all` em vez de `nikic/php-parser`
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** precisamos analisar PHP legado sem executá-lo.

**Decisão:** usar o tokenizer nativo do PHP 8.2, disponível tanto no
Laragon quanto na VPS (`tokenizer: yes`, confirmado).

**Consequência:** não conseguimos reescrever expressões PHP complexas
(só strings literais e blocos HTML inline). Para o escopo atual — achar
caminhos de imagem em `echo`, `src="..."` e variáveis simples — é
suficiente.

**Revisão futura:** se aparecer projeto com PHP gerado dinamicamente,
adotar `nikic/php-parser` via Composer (disponível na VPS).

---

## D-004 · Rascunho como delta JSON, não cópia da página
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** o modelo atual (`content.json` inteiro) torna impossível
descartar um campo sem perder os outros.

**Decisão:** `cms_drafts.patch_json` guarda `{element_id: new_value}`.

**Consequência:** preview precisa aplicar o delta sobre o estado atual;
publicação aplica o delta elemento a elemento. Mais lógica, muito mais
controle.

---

## D-005 · Publicação por patch cirúrgico, nunca reescrita total
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** `apply-index.php` recebe o HTML inteiro do browser e
sobrescreve o arquivo. Qualquer bug de DOM vira corrupção.

**Decisão:** cada adaptador escreve só o nó/offset que lhe pertence, via
`AtomicWriter` (tmp + `rename`).

**Consequência:** precisa de parser robusto por tecnologia. Mas elimina a
classe de falha "publicou HTML quebrado".

---

## D-006 · `project_id` do request nunca define caminho
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** path traversal é o risco número 1 em CMS multi-tenant.

**Decisão:** o request traz `project_id`; o servidor busca `rel_path` no
banco e resolve com `realpath` + `str_starts_with`. O cliente nunca
informa caminho.

**Consequência:** `project_id` forjado resulta em 404 ou acesso ao próprio
projeto do usuário, nunca a outro diretório.

---

## D-007 · Scanner sem método de escrita
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** separação leitura/escrita é exigência explícita do projeto.

**Decisão:** classes do scanner não têm `write()`, `save()`, `unlink()`.
Não é controle de permissão — é ausência de API.

**Consequência:** impossível um bug no scanner alterar arquivo.

---

## D-008 · Performance: hash + mtime, varredura manual
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** varrer 3 projetos com dezenas de arquivos a cada acesso ao
painel é desperdício.

**Decisão:** `cms_pages.content_hash` (sha256) + `filemtime`. Scanner
incremental por padrão; varredura completa só por botão explícito.

**Consequência:** primeira varredura é lenta; as seguintes são quase
instantâneas. Estado do mapeamento fica visível no card do projeto.

---

## D-009 · Sem Composer na FASE 1
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** `composer` existe na VPS, mas adicionar dependências aumenta
superfície de ataque e complexidade de deploy.

**Decisão:** FASE 1–3 sem dependências externas. Autoload PSR-4 próprio
(simples `spl_autoload_register`). PHPUnit só quando houver tempo de
montar o CI.

**Consequência:** testes rodam via runner próprio (`tests/run.php`).
Migrar para PHPUnit depois é trivial.

---

## D-010 · Bloquear `admin/cms/` na web
**Data:** 2026-11-16 · **Fase:** 0

**Contexto:** código interno acessível por URL é superfície de ataque
(exatamente o que aconteceu com `admin/tools/`).

**Decisão:** `.htaccess` bloqueia `admin/cms/` inteiro. Só `admin/index.php`
e `admin/api/*.php` são entrypoints.

**Consequência:** views não podem ser chamadas diretamente. É o
comportamento desejado.

---

## D-011 · Guard CLI obrigatório em `admin/tools/`
**Data:** 2026-11-16 · **Fase:** 1.0

**Contexto:** `admin/tools/*.php` era executável por HTTP. Um `GET` em
`extract-content.php` regravou `content.json` em produção.

**Decisão:** três camadas independentes — `.htaccess` na pasta,
`_guard.php` (`PHP_SAPI !== 'cli'` → 404) e `RewriteRule` na raiz.
Todo script novo em `admin/tools/` **deve** incluir `_guard.php` como
primeira instrução após `declare(strict_types=1)`.

**Consequência:** o teste `security-tools-guard.php` falha se um script
novo esquecer o guard. É uma regra verificada, não uma convenção.

---

## D-012 · Projetos legados recebem `data-cms-id` (decisão do Marcos)
**Data:** 2026-11-16 · **Fase:** 1

**Contexto:** `kit-activites-tea` e `reliure-artisanale` não têm nenhum
marcador CMS. Duas opções: adaptador heurístico (arriscado) ou aplicar o
padrão.

**Decisão:** aplicar `data-cms-id` / `data-cms-type` no HTML dos dois
projetos. Edição 100% segura, sem heurística de posição.

**Consequência:** exige uma etapa de instrumentação dos HTMLs existentes
(entra na FASE 3, junto com o `ComponentAdapter`). Até lá, esses
projetos aparecem com a maioria dos elementos em `read_only` — o que é
o comportamento correto e visível no painel.
