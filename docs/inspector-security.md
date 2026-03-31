# Política Operacional do SparkInspector

O SparkInspector é o painel de observabilidade nativo do SparkPHP. O comportamento básico de segurança já existe: o Inspector só ativa automaticamente em `APP_ENV=dev`. Este documento formaliza e endurece essa política — explicitando o comportamento por ambiente, o que é mascarado por padrão e o que precisa de configuração intencional.

---

## Comportamento por ambiente

| `APP_ENV` | Inspector | Masking padrão | Acesso a `/_spark` |
|---|---|---|---|
| `dev` | Ativo por default; desativar com `SPARK_INSPECTOR=off` | Off por default; ativar com `SPARK_INSPECTOR_MASK=true` | Disponível |
| `staging` | Inativo por default; ativar com `SPARK_INSPECTOR=on` | **On** (automático) | Proteger por IP ou autenticação |
| `production` | **Inativo** por default | — | Inacessível |

O Inspector desliga fora de `dev` via `resolveEnabled()` no bootstrap. Em qualquer ambiente que não seja `dev`, é necessário `SPARK_INSPECTOR=on` explicitamente para ativá-lo.

> **Nota:** Em alguns times, até o ambiente `dev` pode exigir masking parcial — por exemplo, quando desenvolvedores têm acesso a dados reais de clientes mesmo localmente. Nesse caso, defina `SPARK_INSPECTOR_MASK=true` explicitamente no `.env` de desenvolvimento.

---

## Dados mascarados por padrão

Fora de `APP_ENV=dev`, o masking é ativado automaticamente. Os seguintes campos são substituídos por `***` em qualquer payload (headers, inputs, sessão):

**Headers HTTP:**
- `Authorization`
- `Cookie`
- `X-API-Key`
- `Api-Key`

**Campos de formulário e input:**
- `password`
- `passwd`
- `token`
- `secret`
- `api_key`
- `api-key`
- `passphrase`

A correspondência é por `str_contains` no nome do campo (case-insensitive), então `password_confirmation`, `x-api-key-prod` etc. também são mascarados.

**AI prompts e respostas:**
Controlado separadamente por `SPARK_AI_MASK` (padrão `true`). Prompts, sistema, instruções e conteúdo de tool calls são mascarados por padrão.

---

## Variáveis de configuração

As variáveis canônicas do Inspector são as já publicadas em `.env.example`. Não renomeá-las sem ciclo de deprecação.

```dotenv
# Ativar/desativar o Inspector
# auto = só em dev | on = sempre ativo | off = nunca
SPARK_INSPECTOR=auto

# Masking de dados sensíveis (headers, inputs, session)
# Padrão: false em dev, true em staging/production (automático)
# Defina explicitamente para sobrescrever o comportamento por ambiente
SPARK_INSPECTOR_MASK=false

# Masking de AI prompts/respostas
# Padrão: true em todos os ambientes
SPARK_AI_MASK=true

# Prefixo da URL do painel Inspector
SPARK_INSPECTOR_PREFIX=/_spark

# Quantidade de requests mantidos no histórico persistido
SPARK_INSPECTOR_HISTORY=150

# Threshold para alertas de lentidão (ms)
SPARK_INSPECTOR_SLOW_MS=250
```

Qualquer renomeação de variável (ex.: `SPARK_INSPECTOR_ENABLED`, `SPARK_INSPECTOR_MASK_INPUTS`) deve ser tratada como mudança de contrato com deprecação documentada — não como simples refactor interno.

---

## Staging e produção

**Staging:** se você precisar do Inspector em staging, ative com `SPARK_INSPECTOR=on` e proteja o endpoint `/_spark` por IP, autenticação básica ou middleware dedicado. O masking estará ativo por padrão — verifique que nenhum dado sensível está chegando ao painel antes de expor para o time.

**Produção:** nunca exponha `/_spark` publicamente. O Inspector em produção é uma superfície de ataque: ele exibe SQL, headers, payloads e logs de aplicação. Se necessário para debugging pontual, use `SPARK_INSPECTOR=on` temporariamente e reverta imediatamente.

---

## Dados coletados pelo Inspector

O Inspector armazena em `storage/inspector/`:

- Rota, método HTTP, status, duração
- Headers da requisição (mascarados conforme política)
- Inputs e query params (mascarados conforme política)
- Queries SQL com bindings e duração
- Cache hits/misses
- Logs da aplicação
- Eventos disparados
- Jobs da fila
- E-mails enviados
- Chamadas de AI (mascaradas por padrão)
- Exceções com stack trace

**Retenção:** controlada por `SPARK_INSPECTOR_HISTORY`. Padrão: últimos 150 requests. Os arquivos são sobrescritos automaticamente.

---

## Recomendações operacionais

1. Em staging, sempre defina `SPARK_INSPECTOR_MASK=true` explicitamente.
2. Não comite `.env` com `SPARK_INSPECTOR=on` para branches de produção.
3. Revise `storage/inspector/` antes de compartilhar diagnósticos — pode conter dados sensíveis.
4. O endpoint `/_spark` não tem autenticação nativa — proteja por camada de infraestrutura se precisar expor.
5. Em projetos com dados de saúde, financeiros ou legalmente sensíveis: mantenha o Inspector desabilitado em produção sem exceção.

---

## Referência cruzada

- [Fronteira do produto](00-product-scope.md) — status do Inspector (first-party opcional, beta)
- [Regras de inferência](inference-rules.md) — garantia de que o Inspector está off em production por padrão
- [Releases & Compatibilidade](14-releases.md)
