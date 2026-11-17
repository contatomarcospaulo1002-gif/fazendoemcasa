# FASE 4 — Editor Visual

Concluída em 2026-11-16. Suíte: **462/462**.

---

## 1. O que a fase entrega

Um editor de três colunas onde o utilizador altera conteúdo e vê o
resultado **antes** de qualquer coisa tocar o disco.

O produto da fase é um **rascunho por delta**: apenas os campos
alterados, nunca a página inteira.

---

## 2. Por que delta e não página inteira

| Abordagem | Problema |
|---|---|
| Guardar o HTML completo editado | perde formatação, comentários, PHP embutido |
| Reescrever o ficheiro a partir do DOM do browser | normaliza e destrói o original |
| **Delta `element_id => valor`** | cirúrgico, reversível, comparável |

O delta permite:

- descartar um campo sem perder os outros;
- comparar antes/depois elemento a elemento;
- publicar em lote na FASE 6;
- custo de armazenamento mínimo.

---

## 3. Componentes

| Componente | Responsabilidade |
|---|---|
| `PatchValidator` | rejeita o patch inteiro se qualquer campo falhar |
| `DraftService` | um rascunho `open` por página, mesclagem por campo |
| `PreviewRenderer` | aplica o patch ao HTML **em memória** |
| `PreviewService` | lê o ficheiro, valida o caminho, delega ao renderer |

### PatchValidator — tudo ou nada

Um patch com um único campo inválido é rejeitado inteiro e devolve
`clean: []`. O chamador nunca precisa de revalidar nem de filtrar
parcialmente. Regras:

- o elemento tem de existir **e** pertencer à página/projeto;
- nível tem de ser `editable` ou `needs_adapter`;
- tipos de mídia exigem caminho relativo (`/`, `./`, `../`) ou `http(s)`;
- `javascript:`, `data:` e `vbscript:` são bloqueados;
- texto limitado a 20 000 caracteres, URL a 2 000.

### DraftService — um rascunho por página

`save()` mescla com o patch existente. Editar o campo A e depois o
campo B resulta em dois campos pendentes, não num só. Isso evita a
perda silenciosa que aconteceria se cada gravação substituísse o
rascunho anterior.

### PreviewRenderer — cirúrgico

Localiza o nó por `data-cms-id` **percorrendo os nós e comparando o
atributo em PHP**, nunca interpolando o valor numa expressão XPath. Um
id com aspas ou colchetes não quebra a consulta nem permite injeção de
XPath.

Comportamento por tipo:

| Tipo | O que muda |
|---|---|
| texto | conteúdo do nó |
| imagem / logo / banner | atributo `src` |
| link | atributo `href` (texto preservado) |
| background | `background-image` no `style` |

---

## 4. A garantia central

> O preview mostra exatamente o que a FASE 6 vai escrever.

`PreviewRenderer` é o mesmo mecanismo de patch que o publicador usará.
Não há dois caminhos de renderização que possam divergir.

**E nada toca o disco.** Verificado por teste: depois de guardar um
rascunho e renderizar o preview, o ficheiro em disco continua
byte-a-byte igual ao original.

---

## 5. Bug encontrado testando no navegador

O `HtmlAnalyzer` devolvia, para um elemento `link`, o **texto visível**
como valor editável. No editor, o campo "Destino do link" aparecia
preenchido com `Ver kits` em vez de `/kits`.

Corrigido no analisador: para `type === 'link'`, o valor extraído é o
`href`. O texto visível é outro elemento de conteúdo e não deve ser
confundido com o destino.

Visível apenas ao abrir o editor e selecionar o link — os testes de API
passavam porque não validavam a semântica do valor.

---

## 6. UI

`/admin/cms-editor.php?id=N&page=M`

- **Coluna esquerda:** árvore de elementos, com ponto colorido por
  nível, valor atual e filtro.
- **Centro:** iframe com `sandbox="allow-same-origin"`, viewports
  desktop (100%), tablet (820px) e mobile (390px).
- **Direita:** propriedades do elemento selecionado — textarea para
  texto com contador, input para URL/imagem.
- **Topo:** badge de alterações pendentes, descartar, guardar rascunho.

Elementos não graváveis abrem em modo leitura com o motivo visível e
**sem campo de edição** — não há como tentar editar algo bloqueado.

---

## 7. API

`admin/api/cms-editor.php`

| Método | Rota | Efeito |
|---|---|---|
| GET | `?project=N&page=M` | elementos + patch + csrfToken |
| GET | `?project=N&page=M&preview=1` | HTML com rascunho aplicado |
| POST | `…&action=save` | grava patch (CSRF) |
| POST | `…&action=discard` | descarta rascunho (CSRF) |
| POST | `…&action=discard-element` | descarta 1 campo (CSRF) |

**Ordem de verificação deliberada:** CSRF e permissão **antes** de
qualquer consulta. Um POST forjado não deve sequer descobrir se a
página existe. Depois `Auth::check()`, depois a validação
`page_id + project_id`.

---

## 8. Testes

| Arquivo | Verificações | Cobre |
|---|---|---|
| `PatchValidatorTest.php` | 23 | tudo-ou-nada, esquemas perigosos, limites |
| `PreviewRendererTest.php` | 23 | patch cirúrgico, resto do HTML intacto |
| `EditorIntegrationTest.php` | 35 | rascunho, mesclagem, preview, disco intocado |
| `ApiAuthTest.php` (+13) | 50 | CSRF antes de 404, permissões, ações |
| `ExposureGuardTest.php` (+13) | 49 | editor protegido, zero escrita em disco |

---

## 9. O que a FASE 5 e 6 herdam

- `cms_drafts` com `patch_json`, status e autor.
- `PreviewRenderer` como mecanismo único de patch.
- `PatchValidator` como porteiro de toda escrita futura.
- Garantia testada de que o preview nunca grava.

A FASE 6 acrescenta: backup obrigatório, `AtomicWriter`, verificação
pós-escrita por hash e re-parse, e rollback automático.
