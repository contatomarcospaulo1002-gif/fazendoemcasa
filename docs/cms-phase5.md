# FASE 5 — Biblioteca de Mídia

Concluída em 2026-11-16. Suíte: **531/531**.

---

## 1. O que a fase entrega

Uma biblioteca de imagens por projeto, com upload validado por
**conteúdo real**, índice de onde cada imagem é usada, e substituição
com escopo explícito.

---

## 2. A regra central do upload

> O tipo do ficheiro vem do conteúdo. Nunca do nome, nunca do header.

Um `foto.png` que contenha `<?php system($_GET["c"]); ?>` é rejeitado.
Um `malicioso.php` que contenha um PNG válido é aceite — **como PNG**,
porque a extensão de destino é derivada do MIME detectado, não do nome
enviado.

| Verificação | Como |
|---|---|
| Tipo real | `finfo` sobre o conteúdo |
| Extensão de destino | derivada do MIME (`image/png` → `png`) |
| Nome no disco | hash + extensão derivada, gerado pelo sistema |
| Tamanho | máx. 8 MB |
| Dimensões | `getimagesize` — se não mede, não é imagem |

### SVG: tratado como código, não como imagem

SVG é XML executável. Antes de aceitar, o conteúdo é inspecionado:

- `<script`, `javascript:`, `onload=`, `onerror=`, `onclick=`
- `<foreignObject`, `<iframe`, `<embed`, `<object`
- `<!DOCTYPE`, `<!ENTITY` (XXE)

Qualquer um destes rejeita o ficheiro.

### Segunda barreira: `.htaccess` na pasta

Mesmo um ficheiro que passasse à validação não seria executado:

```apache
php_flag engine off
<FilesMatch "\.(php|phtml|phar|cgi|pl|py|sh)$">
  Require all denied
</FilesMatch>
Options -ExecCGI -Indexes
```

---

## 3. Onde o ficheiro é escrito

Apenas dentro de `<projeto>/uploads/`. O caminho é validado com
`realpath` contra a raiz do projeto antes da escrita, e o nome no disco
é gerado pelo sistema — não há componente vindo do utilizador no
caminho final. Path traversal é impossível por construção.

---

## 4. Deduplicação por checksum

Enviar a mesma imagem duas vezes devolve o registo existente em vez de
criar uma cópia. O checksum SHA-256 é único por projeto.

---

## 5. Índice de referências

`cms_media_references` responde à pergunta que torna a substituição
segura: **onde esta imagem é usada?**

O índice é reconstruído a partir dos elementos registados, comparando o
valor de cada elemento de mídia com o caminho das mídias da biblioteca.

Um detalhe importante que só apareceu no teste de integração: a chave
única é `(media_id, element_id)`, não `(media_id, ref_path)`. Três
elementos podem legitimamente usar o mesmo ficheiro — e a versão
inicial do esquema registava apenas um deles.

---

## 6. Substituição com escopo explícito

Substituir uma imagem pode afetar várias páginas sem que o utilizador
saiba. A biblioteca exige que o escopo seja declarado:

| Escopo | Alcance |
|---|---|
| `single` | apenas o elemento indicado |
| `page` | todos os usos na página indicada |
| `all` | todos os usos no projeto |

A UI mostra o número real de usos e a lista de páginas **antes** da
confirmar. Nada é decidido silenciosamente.

**A substituição não escreve no disco.** Ela cria rascunhos, que só
viram ficheiro na FASE 6. A promessa da FASE 4 continua intacta.

---

## 7. Remoção protegida

Uma mídia em uso **não pode ser removida** — a API devolve 409 com a
lista de referências, e a UI nem mostra o botão. Apagar uma imagem em
uso deixaria páginas com referências quebradas.

---

## 8. Bug encontrado testando no navegador

A miniatura apontava para `/loja-demo/uploads/...` porque o `rel_path`
inclui o prefixo do projeto. Mas o projeto **é** a raiz servida — o
prefixo não faz parte da URL pública.

Corrigido: a API devolve um campo `url` calculado a partir do `base_url`
do projeto, removendo o prefixo. Sem `base_url`, usa a raiz `/`.

Os testes de API não apanhavam porque validavam o JSON, não o carregamento
efetivo da imagem (`naturalWidth`).

---

## 9. Testes

| Arquivo | Verificações | Cobre |
|---|---|---|
| `UploadValidatorTest.php` | 28 | PHP disfarçado, 4 vetores SVG, XXE, limites |
| `MediaStorageTest.php` | 18 | escrita confinada, .htaccess, dedupe |
| `MediaReferenceTest.php` | 20 | índice, escopos, disco intocado |
| `ApiAuthTest.php` (+11) | 61 | auth, CSRF, 404/409/422 |
| `ExposureGuardTest.php` (+14) | 63 | mídia protegida, smoke |

**Verificado no navegador:** upload real de PNG via canvas, miniatura
carregando (`naturalWidth: 1`), reindexação, detalhe com referências
por página, "3 usos", ausência do botão remover em mídia usada.

---

## 10. O que a FASE 6 herda

- `MediaStorage` com escrita confinada e validada.
- `ReferenceIndex` para mostrar alcance antes de publicar.
- `MediaReplacer` que produz rascunhos, não escritas.
- A garantia testada de que nada toca o disco antes da publicação.
