# SparkPHP — Plano de Implementação

## Contexto

As oito macrofases do TASKS.md aparecem como concluídas, o que reduz o valor do arquivo como roadmap ativo. O produto está em `0.x` sem política clara de maturidade por subsistema, sem exemplo prático compacto no README e com a DSL de templates crescendo além do necessário. A fronteira entre core/first-party/experimental não estava definida publicamente.

O maior problema não é falta de features — é excesso de superfície para um framework cuja promessa central é justamente ter menos superfície.

**Objetivo:** Recentrar a narrativa, reorganizar o roadmap, formalizar contratos implícitos e endurecer a política operacional do Inspector — sem adicionar novas features ao core antes de resolver incoerências existentes.

---

## Regras permanentes

Aplicar em toda fase, sem exceção:

1. Não expandir o core antes de resolver incoerências entre filosofia, docs e runtime.
2. Toda mudança relevante entrega código + teste + doc no mesmo ciclo.
3. Toda decisão de feature passa pelo filtro: mais curto? mais claro? mais observável? reduz boilerplate real?
4. Jamais mascarar incoerência com documentação — corrigir o runtime ou reduzir a promessa.
5. Breaking changes apenas em fases posteriores, depois de maturidade publicada.

---

## Fase 1 — Redefinir a fronteira do produto

**Objetivo:** Definir publicamente o que é core, o que é first-party opcional e o que é experimental.

**Arquivos:**
- `docs/00-product-scope.md` *(criar)*
- `README.md` — consolidar exemplo de 60 segundos e seção "Núcleo do Spark"

**Instruções:**

1. Criar `docs/00-product-scope.md` com a matriz de pertencimento usando **duas colunas independentes**:
   - **Categoria**: Core obrigatório / First-party opcional / Experimental
   - **Maturidade**: Estável / Beta / Experimental

   Evitar misturar os dois conceitos em uma só coluna. Um subsistema pode ser "First-party opcional" e ao mesmo tempo "Beta" — como o SparkInspector.

2. No `README.md`, consolidar o exemplo de 60 segundos no **topo**, logo após o tagline do produto. O exemplo deve:
   - Mostrar rota + view + o que acontece com HTML e com JSON
   - Mostrar `php spark routes:list` e `/_spark`
   - Ser a prova prática da tese, não apenas mais texto
   - **Não duplicar** exemplos já existentes em `docs/README.md` — substituir ou eliminar o que se sobreponha

3. Remover seções redundantes do `README.md` que o exemplo já cobriu. A abertura do produto deve ser enxuta.

**Critério de aceite:** Leitor entende escopo em menos de 2 minutos. Categoria e maturidade aparecem como conceitos distintos na matriz.

---

## Fase 2 — Transformar o TASKS.md em roadmap real

**Objetivo:** TASKS.md deve mostrar apenas o que está por fazer — não o histórico completo.

**Arquivos:**
- `TASKS.md` — reescrever com formato Now/Next/Later/Icebox
- `docs/roadmap-archive.md` *(criar)* — recebe o histórico das macrofases concluídas
- `CHANGELOG.md` — preservar apenas releases públicas e mudanças de contrato

**Instruções:**

1. Criar `docs/roadmap-archive.md` para o histórico de execução interna das 8 macrofases. Este arquivo não é o CHANGELOG — é o registro de planejamento.

   Distinção importante:
   - `CHANGELOG.md` → releases públicas, breaking changes, features por versão
   - `docs/roadmap-archive.md` → execução das macrofases de roadmap interno
   - `docs/14-releases.md` → política pública de versionamento e maturidade

2. Reescrever `TASKS.md` com estrutura:
   ```
   ## Now — em execução
   ## Next — próximo ciclo
   ## Later — planejado, não imediato
   ## Icebox — adiado indefinidamente
   ## Decisões em aberto
   ## Histórico
   ```

3. Preencher o roadmap apenas com pendências reais derivadas deste plano.

4. Seção "Decisões em aberto" deve registrar explicitamente:
   - Política de maturidade por subsistema
   - Opt-out de convenções sensíveis
   - Masking de dados no Inspector em staging
   - Modularização do AI SDK como pacote Composer separado

**Critério de aceite:** TASKS.md responde "o que está sendo feito agora" em menos de 10 segundos de leitura. Histórico está em `docs/roadmap-archive.md`.

---

## Fase 3 — Alinhar filosofia, docs e runtime

**Objetivo:** Nenhuma feature central pode estar em estado "docs promete mais do que o runtime garante".

**Arquivos:**
- `docs/contract-matrix.md` *(criar)*

**Instruções:**

1. Criar `docs/contract-matrix.md` com tabela de coerência por comportamento (status: Coerente / Parcial / Incoerente / Não implementado).

2. Para auditar cada comportamento, verificar **todos** os arquivos relevantes — não apenas o principal:
   - `Router.php` + `Response.php` → view espelho, inferência de status
   - `Response.php` → conteúdo por Accept, null→404, POST→201
   - `Container.php` → route model binding, resolução de services
   - `Model.php` + `Schema.php` → fillable inferido
   - `Middleware.php` → ordem, escopo, comportamento em falha
   - `EventEmitter.php` → auto-discovery por filename
   - Testes existentes → confirmar cobertura de contrato

3. **Regra principal:** quando houver divergência, preferir reduzir a promessa antes de expandir o runtime.

   **Exceção crítica:** quando a divergência tocar o núcleo da proposta pública — file-based routing, resposta inteligente por Accept, view espelho, convenção de middleware ou observabilidade básica — preferir **corrigir o runtime** antes de reduzir a promessa, salvo se o custo arquitetural for desproporcional. Esses comportamentos são o produto.

4. Para cada item com status Parcial ou Incoerente: documentar a limitação antes de corrigir.

5. Criar testes de contrato para os 5 comportamentos mais críticos: view espelho, JSON/HTML por Accept, null→404, POST→201, route model binding.

**Critério de aceite:** Toda feature central tem comportamento documentado + teste de contrato. Nenhum status "Incoerente" sem plano de correção.

---

## Fase 4 — Enxugar e estabilizar a DSL do Spark Template

**Objetivo:** Template engine explicável em 2 páginas. Evitar que a DSL vire um segundo framework dentro do framework.

**Arquivos:**
- `docs/template-core-vs-advanced.md` *(criar)*
- `docs/04-views.md` — reorganizar com seção de uso básico em 1 tela

**Instruções:**

1. Classificar todas as diretivas em três níveis distintos:

   **Core da linguagem** — sintaxe-base, aprende-se primeiro:
   `@layout`, `@title`, `@content`, `@partial`, `@if/@endif`, `@foreach/@endforeach`, `{{ }}`

   **Core de formulários** — conveniências de domínio para o caso web mais comum:
   `@form`, `@input`, `@submit`

   A separação importa: `@input` e `@submit` são conveniências de domínio, não sintaxe-base. Um projeto de API pura pode ignorar o core de formulários completamente.

   **Avançado** — usar quando o core não for suficiente:
   `@component/@slot`, `@cache`, `@lazy`, `@stack`, `@once`, `@for`, `@while`, `@repeat`, `@first/@last`, `@auth`, `@role`, `@can`, `@dev`, `@prod`, `{!! !!}`, `@php`

   **Candidatos à revisão** — sem remoção imediata, mas com ciclo de deprecação futuro:
   `@bodyClass`, `@active`, `@img`, `@icon`, `@meta`, pipes numerosos (`money`, `bytes`, `initials`)

2. Criar `docs/template-core-vs-advanced.md` com esta classificação, a política de estabilidade por nível e a regra de não remover sem ciclo de deprecação documentado.

3. Reorganizar `docs/04-views.md` para que a seção de uso básico caiba em 1 tela.

**Critério de aceite:** `docs/04-views.md` tem uma seção "Uso básico completo" que cabe em 1 tela.

---

## Fase 5 — Formalizar contratos de inferência e previsibilidade

**Objetivo:** Todo comportamento implícito deve ser previsível, testável e com opt-out quando sensível.

**Arquivos:**
- `docs/inference-rules.md` *(criar)*

**Instruções:**

1. Criar `docs/inference-rules.md` com duas seções simétricas:

   **"O que o Spark infere"** — com regra de precedência para cada item:
   - View espelho por caminho de rota
   - Formato por Accept header (HTML vs JSON)
   - Status por método + tipo de retorno (null→404, POST+objeto→201)
   - Route model binding por type-hint de Model + param URL
   - DI de services por type-hint de qualquer outra classe
   - Fillable inferido do schema
   - Relações inferidas de FKs convencionais
   - Middleware por filename e localização

   **"O que o Spark nunca infere"** — garantias negativas:
   - Nunca executa handler sem rota correspondente
   - Nunca persiste dados sem chamada explícita
   - Nunca escapa menos do que o necessário
   - Nunca silencia erro de middleware
   - Nunca ativa Inspector em production por default

2. Documentar opt-out explícito para:
   - Route model binding: usar `string $id` ou `int $id` no type-hint para receber o valor bruto
   - View espelho: retornar `Response` diretamente desativa a inferência
   - Status inferido: retornar `Response` com status explícito

3. Documentar mensagens de erro esperadas quando cada inferência falhar.

**Critério de aceite:** Cada comportamento implícito importante tem regra de precedência documentada + opt-out disponível.

---

## Fase 6 — Formalizar e endurecer a política operacional do Inspector

**Objetivo:** O Inspector já desliga fora de `dev` por padrão. O foco desta fase é formalizar a política, endurecer o masking e documentar explicitamente o comportamento por ambiente.

**Arquivos:**
- `docs/inspector-security.md` *(criar)*
- `core/SparkInspector.php` — endurecer masking padrão por ambiente
- `.env.example` — melhorar documentação das variáveis existentes

**Instruções:**

1. No `core/SparkInspector.php`, método `sanitize()`:
   - Manter `SPARK_INSPECTOR` e `SPARK_INSPECTOR_MASK` como nomes canônicos — **não renomear** sem política de deprecação
   - Tornar masking automático em ambientes não-`dev`: quando `APP_ENV != dev`, `SPARK_INSPECTOR_MASK` padrão passa a ser `true`
   - Ampliar lista de chaves sensíveis: incluir `x-api-key`, `api-key`, `api_key`, `passphrase`

2. Documentar em `docs/inspector-security.md`:
   - Comportamento atual por ambiente (dev/staging/production)
   - O que é mascarado por padrão e o que requer configuração explícita
   - Nota sobre `dev` com dados reais: mesmo em `dev`, alguns times precisam de masking — é opt-in explícito via `SPARK_INSPECTOR_MASK=true`
   - Dados coletados e política de retenção (`SPARK_INSPECTOR_HISTORY`)
   - Aviso: o endpoint `/_spark` não tem autenticação nativa

3. Melhorar comentários no `.env.example` para as variáveis do Inspector.

4. Qualquer proposta de novos nomes de variáveis (ex.: `SPARK_INSPECTOR_ENABLED`) deve ser tratada como mudança de contrato com deprecação documentada — não como refactor.

**Critério de aceite:** `APP_ENV=production` → Inspector inacessível por padrão. `APP_ENV=staging` com `SPARK_INSPECTOR=on` → masking ativo automaticamente.

---

## Fase 7 — Publicar maturidade por subsistema

**Objetivo:** O usuário passa a saber onde pode confiar plenamente e onde deve adotar com cautela.

**Arquivos:**
- `README.md` — adicionar tabela com colunas Categoria e Maturidade
- `docs/14-releases.md` — adicionar coluna Categoria à tabela de maturidade

**Instruções:**

1. Na tabela de maturidade, usar **duas colunas independentes**:
   - Categoria: Core obrigatório / First-party opcional / Experimental
   - Maturidade: Estável / Beta / Experimental

   Isso evita confusão entre "onde o subsistema pertence" e "qual a garantia da sua API".

2. Definir o significado de cada nível de maturidade:
   - **Estável**: contrato público fixo, breaking changes apenas em major
   - **Beta**: funciona em produção, API pode mudar em minors com upgrade guide
   - **Experimental**: não usar em produção sem avaliação, pode mudar sem aviso

3. Definir critérios de promoção de maturidade (Experimental → Beta → Estável).

4. Atualizar `docs/14-releases.md` com a tabela e a política aplicada a cada subsistema.

**Critério de aceite:** A tabela no README tem Categoria e Maturidade como colunas separadas. O usuário consegue distinguir "pertence ao core" de "a API é estável".

---

## Fase 8 — Documentar modularização do AI SDK como decisão em aberto

**Objetivo:** Core permanece pequeno. AI/Search crescem como módulos sem inflar o runtime base.

> **Pré-requisito:** Fases 1–7 concluídas.

**Instruções:**

1. Auditar especificamente onde `core/Ai.php` é carregado no boot:
   - `core/Bootstrap.php` — verificar se AiManager é instanciado no boot geral ou sob demanda
   - Container — verificar se AI está registrado como singleton no boot ou como lazy binding
   - `core/helpers.php` — verificar se o helper `ai()` pressupõe AI ativa
   - `core/SparkInspector.php` — verificar se o coletor de AI é instanciado mesmo sem uso de AI

2. Registrar o resultado da auditoria em `docs/00-product-scope.md` na seção "Decisão de modularização futura".

3. Se `core/Ai.php` é carregado incondicionalmente no boot: registrar no `TASKS.md` como pendência de lazy loading — mas **não implementar agora**.

4. Proposta de separação como `sparkphp/ai` (pacote Composer independente) fica como item "Later" no TASKS.md.

5. Não implementar a separação nesta fase — apenas documentar o estado real e a decisão em aberto.

**Critério de aceite:** Existe documentação clara de onde AI é carregada no boot e qual é o plano para lazy loading.

---

## Artefatos entregues por fase

| Fase | Arquivos novos | Arquivos modificados |
|---|---|---|
| 1 | `docs/00-product-scope.md` | `README.md` |
| 2 | `docs/roadmap-archive.md` | `TASKS.md` |
| 3 | `docs/contract-matrix.md` | Testes |
| 4 | `docs/template-core-vs-advanced.md` | `docs/04-views.md` |
| 5 | `docs/inference-rules.md` | — |
| 6 | `docs/inspector-security.md` | `core/SparkInspector.php`, `.env.example` |
| 7 | — | `README.md`, `docs/14-releases.md` |
| 8 | — | `docs/00-product-scope.md`, `TASKS.md` |

---

## Verificação end-to-end

Após cada fase:

```bash
# Testes devem continuar verdes
composer test

# Rotas devem listar corretamente
php spark routes:list

# Inspector deve respeitar APP_ENV
APP_ENV=production php spark serve
# → GET /_spark deve retornar 404 ou 403

# Lint de sintaxe PHP após mudanças no core
php -l core/SparkInspector.php
```

---

## Referência cruzada dos documentos produzidos

| Documento | Responde |
|---|---|
| `docs/00-product-scope.md` | O que é core, optional, experimental |
| `docs/roadmap-archive.md` | O que foi feito nas 8 macrofases |
| `docs/contract-matrix.md` | Docs prometem o quê vs runtime entrega o quê |
| `docs/template-core-vs-advanced.md` | Quais diretivas pertencem ao core da linguagem |
| `docs/inference-rules.md` | O que o Spark infere e o que nunca infere |
| `docs/inspector-security.md` | Política operacional do Inspector por ambiente |
| `docs/14-releases.md` | Maturidade por subsistema e política de versionamento |
