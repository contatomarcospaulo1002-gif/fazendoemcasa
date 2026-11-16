# Testes — Central CMS multi-projeto

Base: [`cms-roadmap.md`](cms-roadmap.md). Regra: **nenhuma fase é
declarada concluída sem teste que prove o comportamento.**

---

## 1. Estratégia

| Camada | Ferramenta | Onde roda |
|---|---|---|
| Unitário (PHP) | PHPUnit (via `composer require --dev`) | local + CI |
| Integração (banco) | PHPUnit + MySQL de teste | local + CI |
| Funcional (HTTP) | script PHP com `curl` | local |
| Regressão visual | comparação de hash de HTML | local |
| Segurança | casos negativos explícitos | local + CI |

Enquanto `composer` não estiver no fluxo, os testes rodam como scripts
PHP puros em `admin/cms/tests/` com um mini-runner próprio
(`assertSame`, `assertTrue`, contadores). Isso evita bloquear a FASE 1
por infraestrutura.

---

## 2. Projetos de teste

Criar em `admin/cms/tests/fixtures/` (nunca em produção):

| Fixture | Estrutura | Objetivo |
|---|---|---|
| `static-html/` | `index.html` + `assets/` | HTML puro, sem PHP |
| `legacy-php/` | `index.php` com `include` + HTML inline | PHP legado |
| `component-cms/` | `index.html` com `data-cms-id` | padrão novo |
| `nested/` | `pages/sobre.html`, `pages/contato.html` | múltiplas páginas |
| `hostile/` | symlink para fora, `../` em nome, `.php` disfarçado | segurança |

---

## 3. Matriz de testes por fase

### FASE 1 — Central de Projetos

| # | Teste | Tipo |
|---|---|---|
| 1.1 | Descobre `static-html` e `legacy-php` na raiz | unit |
| 1.2 | Ignora `admin/`, `docs/`, `scripts/`, `docker/`, `.git/` | unit |
| 1.3 | Não duplica projeto já cadastrado | unit |
| 1.4 | Cadastro grava `rel_path` correto | integração |
| 1.5 | `project_id` forjado não acessa pasta de outro projeto | **segurança** |
| 1.6 | `rel_path` com `../` é rejeitado | **segurança** |
| 1.7 | Symlink que escapa da raiz é rejeitado | **segurança** |
| 1.8 | Projeto `disabled` não aparece na listagem ativa | unit |

### FASE 2 — Scanner

| # | Teste | Tipo |
|---|---|---|
| 2.1 | Encontra `index.html` e `index.php` como páginas | unit |
| 2.2 | Extrai `src`, `srcset`, `background` de HTML | unit |
| 2.3 | Extrai `url()` de CSS | unit |
| 2.4 | Extrai strings de imagem de PHP sem executar | unit |
| 2.5 | Extrai caminhos de JSON | unit |
| 2.6 | Não executa PHP (verificar que nenhum efeito colateral ocorre) | **segurança** |
| 2.7 | Hash inalterado → não reanalisa | unit |
| 2.8 | Hash alterado → reanalisa | unit |

### FASE 3 — Registry

| # | Teste | Tipo |
|---|---|---|
| 3.1 | Elemento com `data-cms-id` → `editable` | unit |
| 3.2 | Elemento sem adaptador → `read_only` com motivo | unit |
| 3.3 | Upsert não duplica elemento | integração |
| 3.4 | `read()` retorna valor atual correto | unit |
| 3.5 | `write()` altera só o nó alvo | unit |
| 3.6 | `write()` em `read_only` é bloqueado | **segurança** |

### FASE 4 — Editor

| # | Teste | Tipo |
|---|---|---|
| 4.1 | Rascunho guarda só o delta | unit |
| 4.2 | Dois rascunhos no mesmo elemento não se sobrescrevem | integração |
| 4.3 | Preview não escreve em disco | **segurança** |
| 4.4 | Descartar rascunho não altera arquivo | unit |

### FASE 5 — Mídia

| # | Teste | Tipo |
|---|---|---|
| 5.1 | Upload válido grava e registra referência | integração |
| 5.2 | Upload com MIME falso é rejeitado | **segurança** |
| 5.3 | Upload de `.php` disfarçado é rejeitado | **segurança** |
| 5.4 | Substituição atualiza todas as referências | integração |
| 5.5 | Imagem em uso não pode ser excluída | unit |

### FASE 6 — Publicação

| # | Teste | Tipo |
|---|---|---|
| 6.1 | Publicar cria backup antes | integração |
| 6.2 | Falha no meio restaura backup | integração |
| 6.3 | Publicar em projeto A não toca projeto B | **segurança** |
| 6.4 | HTML publicado é válido (re-parse) | unit |
| 6.5 | Cenário de aceitação completo (1–15) | funcional |

### FASE 7 — Histórico

| # | Teste | Tipo |
|---|---|---|
| 7.1 | Toda escrita gera `cms_element_versions` | integração |
| 7.2 | Restaurar página volta ao estado anterior | integração |
| 7.3 | Restaurar não afeta outro projeto | **segurança** |
| 7.4 | Auditoria registra usuário, IP e ação | integração |

---

## 4. Cenário de aceitação (o teste final)

Executado em `kit-activites-tea` (projeto real, não fixture):

1. Abrir `/admin` → ver projetos
2. Selecionar `kit-activites-tea`
3. Abrir Páginas → selecionar `index.html`
4. Ver mapa completo da página
5. Selecionar banner principal
6. Substituir imagem
7. Alterar título
8. Alterar botão
9. Ver desktop e mobile
10. Salvar rascunho
11. Pré-visualizar
12. Publicar
13. Abrir site e conferir
14. Voltar ao painel
15. Selecionar `reliure-artisanale`, alterar
16. Confirmar que `kit-activites-tea` está intacto

**Automação:** script `admin/cms/tests/acceptance.php` que faz as
requisições HTTP e compara hashes dos arquivos antes/depois.

---

## 5. Regressão das páginas públicas

Antes e depois de cada fase, calcular sha256 de:

- `index.html`
- `kit-activites-tea/index.html`
- `reliure-artisanale/index.html`
- `privacidade.html`, `termos.html`

Se algum hash mudar sem que a fase tenha como objetivo alterá-lo, a fase
falhou.

---

## 6. Como rodar

```powershell
# unitários + integração
php admin/cms/tests/run.php

# apenas segurança
php admin/cms/tests/run.php --group=security

# aceitação (precisa do servidor local)
php admin/cms/tests/acceptance.php --base=http://localhost:8080
```

Saída esperada: `OK n/n` ou lista de falhas com arquivo e linha.
