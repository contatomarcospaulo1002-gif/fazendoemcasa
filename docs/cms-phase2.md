# FASE 2 — Scanner de Páginas

Concluída em 2026-11-16. Branch `feat/cms-phase2`.

## O que existe agora

```
admin/cms/Scanner/
  AnalysisResult.php    ← contato imutável entre analisador e banco
  HtmlAnalyzer.php      ← DOMDocument, read-only
  PhpAnalyzer.php       ← token_get_all, estático
  AssetAnalyzer.php     ← CSS (urls), JS, JSON
  ProjectScanner.php    ← orquestra a varredura do diretório
  ScanPersister.php     ← grava incremental por content_hash
  ScanService.php       ← fachada usada pela API
  ScanException.php
```

## Garantias de segurança

| Risco | Mitigação |
|---|---|
| Executar PHP da fixture | `token_get_all` apenas; nunca `include`/`eval` |
| XXE / fetch externo no HTML | `DOMDocument` com `LIBXML_NONET` |
| Escapar do diretório do projeto | varredura parte de `resolveProjectDir()` (já validado pelo `PathGuard`) |
| Symlink para fora | cada link é resolvido e comparado com o prefixo do projeto |
| Loop infinito de diretórios | `RecursiveIteratorIterator` sem seguir links |
| DoS por arquivo gigante | limite de 8 MB por arquivo |
| Ler lixo do sistema | extensões em allowlist + pastas ignoradas |

## Regras de análise

**HTML** — elementos com `data-cms-id` ficam `editable`; imagens e links
internos são sempre inventariados. Links externos e âncoras são ignorados.

**PHP** — nunca executa. Extrai `include`/`require`, literais de string e
variáveis. Se houver HTML embutido ou includes, marca `needs_adapter` e
emite aviso. Os elementos ficam com `editability: needs_adapter` porque
editar um literal PHP às cegas é inseguro.

**CSS** — extrai `url(...)` para o mapa de dependências da FASE 5.

**JS** — apenas inventário, com aviso explícito de não editável.

**JSON** — valida e reporta erro de sintaxe.

## Incremental

Cada arquivo tem `content_hash` (SHA-256). Na varredura seguinte:

| Situação | Efeito |
|---|---|
| hash igual | não regrava; só atualiza `last_scan_at` |
| hash diferente | `UPSERT` e conta como `changed` |
| arquivo desapareceu | `DELETE` e conta como `removed` |

Isso torna a varredura segura para rodar repetidamente e mantém o
inventário fiel ao disco.

## Modelo de dados

| Tabela | Papel |
|---|---|
| `cms_pages` | inventário por projeto (unique `project_id` + `rel_path`) |
| `cms_scans` | histórico de varreduras (found/changed/removed) |

Ambas com `ON DELETE CASCADE` a partir de `cms_projects`.

## API

`admin/api/cms-pages.php`

| Método | Query | Ação |
|---|---|---|
| GET | `?project=N` | lista + resumo + última varredura |
| GET | `?project=N&page=M` | detalhe de uma página |
| GET | `?project=N&kind=html` | filtro por tipo |
| POST | `?project=N&action=scan` | executa varredura (CSRF obrigatório) |

## UI

`admin/cms-project.php?id=N` — lista de páginas com tipo, caminho, título,
contagem de elementos/imagens e tamanho. Filtros por tipo e por caminho.
Botão "Analisar projeto" executa e reporta o resultado.

O card da Central agora mostra páginas, elementos e imagens reais, e o
botão "Gerir" leva para esta tela.

## Testes — 66 novas asserções

```
AnalyzersTest.php ......... 38/38   HTML, PHP, CSS, JS, JSON
ScannerIntegrationTest.php 28/28   varredura, incremental, remoção, isolamento
                          ─────
                          66/66
```

Total do projeto: **190 asserções**, todas passando.

Destaques do que os testes provam:

- `perigo.php` com `file_put_contents` **não** foi executado.
- `node_modules` e arquivos `.txt` foram ignorados.
- Apagar um arquivo do disco remove-o do inventário na varredura seguinte.
- Alterar o título de um HTML atualiza o registro e conta 1 `changed`.
- Dois projetos nunca compartilham páginas.

## Bugs reais encontrados

| # | Bug | Correção |
|---|---|---|
| 1 | Teste esperava 2 elementos, mas imagem marcada conta como 3 | asserção corrigida (a dupla indexação é intencional) |
| 2 | `views/project-pages.php` sem guarda anti-execução | `apply-deny-guard.php` reaplicado |
| 3 | PowerShell corrompeu UTF-8 de `cms.js` | arquivo reescrito e migrado para versionamento com `?v=` |

## Limitações conhecidas

- PHP com HTML embutido: os elementos ficam `needs_adapter` — edição
  segura chega na FASE 3.
- Não há extração de texto de PHP por posição de caractere (necessária
  para patch cirúrgico na FASE 6).
- Assets referenciados por `<img srcset>` e `background-image` inline
  ainda não são mapeados.
- A varredura é síncrona; projetos grandes podem demorar. Fila entra na
  FASE 6 se necessário.

## Próximo passo

FASE 3 — Content Registry + adaptadores, que transforma este inventário
em conteúdo editável com os 4 níveis de editabilidade.
