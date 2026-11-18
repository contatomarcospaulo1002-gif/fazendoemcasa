# Produtos

Cada oferta tem um registo na tabela `cms_products` e uma página no projeto
dela. A página continua a ser editada em **Projetos → Gerir**; a tabela só
guarda o que é comercial e muda com mais frequência: checkout, pixel e estado.

## Onde fica

- Painel: `/admin/cms-products.php` (menu **Central → Produtos**)
- API: `/admin/api/cms-products.php` (sessão + CSRF)
- Tabela: `cms_products`, criada pela migration
  `20261118120000_products.php`
- Registo inicial para conferência: `docs/produtos-hotmart.json`

## O que cada campo faz

| Campo | Para que serve |
|---|---|
| `checkout_provider` | Plataforma de pagamento: Hotmart, Cakto, Kiwify ou checkout próprio |
| `checkout_url` | Link da oferta. Tem de ser HTTPS |
| `meta_pixel_id` | ID do pixel da Meta usado por este produto. Só dígitos |
| `status` | Ativo ou pausado. Pausar **não** altera a página no ar |

## Como a configuração chega à página

As três páginas existentes usam `/product-runtime.js`, que consulta
`/admin/api/product-config.php?slug=<slug>`. O endpoint entrega apenas os campos
públicos necessários: plataforma, URL de checkout e pixel Meta. Assim, o valor
editado no CMS passa a ser aplicado aos botões e ao pixel sem mudar as URLs das
páginas.

O runtime preserva UTMs/parâmetros já associados ao link. Se a API não estiver
disponível, o produto estiver pausado ou não existir no banco, a página conserva
o checkout e pixel escritos nela como fallback. Um produto pausado fica, por
isso, apenas marcado no painel por enquanto; para desativar efetivamente vendas,
retire o checkout ou publique uma página de indisponibilidade própria.

## Criar um produto novo

O modelo inicial está em `templates/produto-digital/`. Copie-o para uma nova
pasta de produto, altere o slug dentro de `index.html` e `product.json`, e
cadastre checkout e pixel no CMS. O template está bloqueado para acesso HTTP
na pasta de origem; só a cópia para a pasta final será acessível. O arquivo
`README.md` da pasta contém a sequência de criação e publicação.

## Como aplicar a migration

```
php admin/cms/bin/migrate.php
```

A migration cria a tabela e insere os três produtos atuais se ainda não
existirem (`INSERT IGNORE`). O `down` apaga a tabela; não toca em nenhum
projeto, página ou arquivo do site.
