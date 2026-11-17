# FASE 6 — Publicação

Concluída em 2026-11-16. Suíte: **639/639**.

---

## 1. O que a fase entrega

O primeiro momento em que algo toca o disco. E é por isso que foi
construída com três barreiras:

1. **Backup obrigatório** antes de qualquer escrita.
2. **Escrita atómica** com ficheiro temporário e `rename`.
3. **Verificação pós-escrita** com rollback automático.

---

## 2. O fluxo, na ordem exata

```
rascunho (delta validado)
  → render em memória (mesmo mecanismo do preview)
  → BackupService::snapshot()        ← OBRIGATÓRIO
  → AtomicWriter::write()            ← temporário + rename
  → postVerify()                     ← re-parse + valores
  → cms_publications (auditoria)
  → rascunho consumido
```

Se qualquer etapa falhar **depois** do rename, o backup é restaurado e
o registo fica como `rolled_back`. O rascunho permanece para nova
tentativa.

---

## 3. AtomicWriter

O conteúdo novo nunca é escrito diretamente no destino:

1. escreve em `<destino>.cms-tmp-<aleatório>`;
2. confere `hash_file` do temporário contra o pretendido;
3. passa pelo verificador de conteúdo;
4. `rename()` — atómico no mesmo volume;
5. confere o hash do destino depois do rename.

Um corte de energia deixa ou o ficheiro antigo ou o novo completo.
Nunca um ficheiro pela metade. Temporários órfãos são removidos em
qualquer falha.

---

## 4. Verificação pós-escrita

Depois de escrever, o Publisher **re-parseia o HTML do disco** e
confirma que cada elemento do patch tem o valor publicado:

| Tipo | Verifica |
|---|---|
| texto | `textContent` do nó |
| imagem/logo/banner | atributo `src` |
| link | atributo `href` |
| background | `style` contém o valor |

Se qualquer verificação falha, o estado no disco é considerado
desconhecido — e o backup volta imediatamente.

---

## 5. Auditoria

`cms_publications` regista cada tentativa:

| Campo | Significado |
|---|---|
| `status` | `applied` / `rolled_back` / `failed` |
| `backup_id` | restauração rastreável |
| `hash_before` / `hash_after` | estado do disco antes e depois |

Verificado no navegador e no banco: `disco == hash_after` → **true**.

---

## 6. A API exige confirmação explícita

`POST /admin/api/cms-publish.php?...&action=publish` com
`{"action":"publish","confirm":true}`.

Sem `confirm: true` → 400. Publicar altera o site vivo; não há modo
automático nem em lote. Auth + permissão de edição + CSRF sempre.

O botão "Publicar" da UI pede confirmação com o número de alterações e
avisa que o backup será criado.

---

## 7. Bug grave encontrado e corrigido

Testando a publicação no navegador: a API dizia `applied`, mas o disco
continuava com o conteúdo antigo.

Causa: o `PreviewRenderer` injeta `<?xml encoding="utf-8"?>` para forçar
UTF-8 no `loadHTML` e removia a declaração **só do início** da string.
Na página de teste, o DOM serializava a declaração **após o DOCTYPE** —
o regex de limpeza não apanhava, o HTML publicado ficava com lixo de
encoding, e a leitura seguinte do servidor servia o ficheiro antigo em
cache.

Correção: remover o nó de processamento **do DOM antes de serializar**
(`removeChild` no XPath `/processing-instruction("xml")`), não da
string depois. O hash pós-escrita agora bate com o disco.

Lição: o teste CLI passava porque validava a string; o navegador expôs
o efeito real no servidor.

---

## 8. Testes

| Arquivo | Verificações | Cobre |
|---|---|---|
| `PublisherTest.php` | 24 | fluxo completo, backup, auditoria |
| `RollbackTest.php` | 16 | verificação falha → disco restaurado |
| `ApiAuthTest.php` (+6) | 67 | auth, CSRF, confirmação, 404/422 |
| `ExposureGuardTest.php` (+13) | 76 | backup/rollback/atomic no código |

O teste de rollback usa um verificador que sempre falha para provar
que: status vira `rolled_back`, o disco volta byte-a-byte ao original,
e o rascunho sobrevive para nova tentativa.

---

## 9. O que a FASE 7 herda

- `cms_publications` e `cms_backups` já auditáveis.
- `BackupService::restore()` pronto para a restauração da UI.
- Hashes antes/depois para o histórico de versões.
