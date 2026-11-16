# FASE 3 — Content Registry & Adaptadores

Concluída em 2026-11-16. Suíte: **363/363**.

---

## 1. O que a fase resolve

A FASE 2 descobriu *quais* arquivos existem. A FASE 3 responde *o que
dentro deles pode ser editado* — e, mais importante, **o que não pode**.

O produto da fase é um registro (`cms_elements`) onde cada elemento
carrega um nível de editabilidade **calculado**, nunca presumido.

---

## 2. Os quatro níveis, na prática

| Nível | Quando o Classifier atribui | UI |
|---|---|---|
| `editable` | Adaptador escreve **e** há referência estável **e** valor legível | edita direto |
| `needs_adapter` | Adaptador escreve, mas falta referência estável | edita com aviso |
| `read_only` | Adaptador declara que **não** sabe escrever | bloqueado + motivo |
| `unconfigured` | Tem referência, mas o valor não foi extraído | pede configuração |

A ordem de precedência é deliberada: **falta de escrita vence tudo**.
Um elemento sem `data-cms-id` mas com valor legível é `needs_adapter`;
um elemento com `data-cms-id` e valor, mas em PHP, é `read_only`.

---

## 3. Adaptadores

| Adaptador | `canWrite` | Estratégia | Por quê |
|---|---|---|---|
| `html` | ✅ | DOMDocument + patch por `data-cms-id` | atributo único e estável |
| `component` | ✅ | resolução por `data-cms-id` + `data-cms-type` | independe da tecnologia |
| `json` | ✅ | `json_decode` → chave → `json_encode` | caminho da chave é único |
| `legacy` | ✅ | delegação ao fluxo legado | já tem backup e verificação |
| `php` | ❌ | tokenizer sem offsets | substituir literal por offset é frágil |
| `css` | ❌ | `url()` por offset | offsets ainda não são guardados |

**Por que PHP e CSS são somente leitura:** um `str_replace` num literal
PHP pode acertar o mesmo texto em contexto de código e quebrar a
sintaxe. Sem um tokenizer que devolva posições confiáveis, a escrita
não é determinística. A FASE 6 introduz esse tokenizer; até lá, o
elemento aparece com o motivo explícito em vez de um botão que falha.

---

## 4. Deduplicação entre adaptadores

Uma página HTML tem dois adaptadores candidatos (`html` e `component`).
Ambos podem produzir o mesmo selector. A regra:

> Prevalece a classificação **mais restritiva**.

Se `html` diz `editable` e `component` diz `needs_adapter`, o elemento
fica `needs_adapter`. Nunca o contrário. Isso impede que a rota mais
permissiva esconda uma limitação real.

---

## 5. Extensões no scanner

Duas adições, ambas **aditivas** — nenhuma contagem existente mudou:

- `AnalysisResult::candidates` — elementos semânticos sem `data-cms-id`
  (`h1`–`h4`, `p`, `button`, `label`). Ficam fora de `elements` para não
  alterar o inventário da FASE 2. O Registry os classifica como
  `needs_adapter` com selector posicional (`p:nth-of-type(1)`).
- `AnalysisResult::jsonData` — o JSON decodificado, antes descartado
  após validação. Permite ao `JsonAdapter` percorrer as chaves.

---

## 6. API

`admin/api/cms-elements.php`

| Método | Rota | Efeito |
|---|---|---|
| GET | `?project=N` | contagem por nível + capacidades dos adaptadores |
| GET | `?project=N&page=M` | elementos da página (com `csrfToken`) |
| GET | `?project=N&page=M&level=L` | filtra por nível |
| POST | `?project=N&action=classify` | reclassifica o projeto (CSRF) |

Garantias:

- `Auth::check()` obrigatório.
- `Csrf::requireValid()` em todo POST.
- `level` inválido → 400 **antes** de qualquer ramo.
- A página é validada contra o `project_id` do request: um par forjado
  devolve 404, nunca dados de outro projeto.

---

## 7. UI

`/admin/cms-content.php?id=N&page=M`

- Quatro cards com a contagem por nível.
- Tabela: selector, tipo, valor atual, adaptador, nível, motivo.
- Filtro por nível e busca por selector/valor.
- Botão "Classificar conteúdo" (POST com CSRF).
- Botão "Conteúdo" em cada linha do inventário de páginas.

O motivo é sempre exibido. Um campo bloqueado sem explicação é um bug
de produto, não uma decisão de segurança.

---

## 8. Bug encontrado em teste de UI

O ramo `GET ?page=` não devolvia `csrfToken`. O botão "Classificar
conteúdo" falhava com *"Token de segurança ausente"* — visível apenas
ao clicar de verdade no navegador, não nos testes de API.

Corrigido e coberto por teste HTTP. Registro aqui porque ilustra a
diferença entre "endpoint responde 200" e "fluxo funciona".

---

## 9. Testes

| Arquivo | Verificações | Cobre |
|---|---|---|
| `ClassifierTest.php` | 26 | os 4 níveis, precedência, invariantes |
| `AdaptersHtmlTest.php` | 29 | html + component, mapeamento de tipo |
| `AdaptersCodeTest.php` | 36 | php/css/json/legacy, honestidade |
| `RegistryIntegrationTest.php` | 23 | persistência, dedup, prune, isolamento |
| `ApiAuthTest.php` (+15) | 37 | CSRF, nível inválido, sessão, adaptadores |
| `ExposureGuardTest.php` (+8) | 36 | internos protegidos, endpoints autenticados |

---

## 10. O que a FASE 4 herda

- `cms_elements` com nível calculado e motivo.
- `Element::isWritable()` como única fonte de verdade para habilitar
  edição na UI.
- `Adapter::capability()` para decidir se um campo aceita escrita.
- Nenhuma escrita em disco ainda: `write()` entra na FASE 6 com
  `AtomicWriter` + backup obrigatório.
