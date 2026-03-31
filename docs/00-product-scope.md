# Fronteira do Produto SparkPHP

Este documento define oficialmente o que pertence ao núcleo do SparkPHP, o que é opcional e o que ainda está em fase experimental.

A fronteira existe para honrar a promessa central do framework: **caber na cabeça do time**.

---

## Matriz de pertencimento

| Subsistema | Categoria | Maturidade |
|---|---|---|
| Bootstrap | Core obrigatório | Estável |
| Router (file-based) | Core obrigatório | Estável |
| Request / Response | Core obrigatório | Estável |
| Middleware pipeline | Core obrigatório | Estável |
| Container (DI) | Core obrigatório | Estável |
| Validator | Core obrigatório | Estável |
| Template Engine (Spark) | Core obrigatório | Beta |
| Database / Query Builder | Core obrigatório | Beta |
| Model (ORM) | Core obrigatório | Beta |
| Session | Core obrigatório | Beta |
| Cache | Core obrigatório | Beta |
| Logger | Core obrigatório | Beta |
| Helpers globais | Core obrigatório | Beta |
| CLI base (`spark`) | Core obrigatório | Beta |
| SparkInspector | First-party opcional | Beta |
| Queue | First-party opcional | Beta |
| Mailer | First-party opcional | Beta |
| EventEmitter | First-party opcional | Beta |
| Schema / Migrations | First-party opcional | Beta |
| Benchmarking | First-party opcional | Experimental |
| OpenAPI Generator | First-party opcional | Experimental |
| Starter Kits | First-party opcional | Beta |
| AI SDK (`ai()`) | Experimental | Experimental |
| Vector Search | Experimental | Experimental |
| AI Conventions (file-based) | Experimental | Experimental |

---

## O que cada categoria significa

### Core obrigatório

Sempre carregado no boot. Faz parte do contrato público do framework. Breaking changes apenas em versões major (a partir do `1.0`).

Regra: se um projeto Spark precisa disso para funcionar, está no core.

### First-party opcional

Mantido pela equipe do SparkPHP, mas só carregado quando o projeto usa o recurso. Não infla o runtime base. Pode evoluir de forma independente.

Regra: se o projeto pode funcionar sem isso, é opcional.

### Experimental

Funcional, mas a API pode mudar sem aviso em versões minor. Não use em produção sem avaliação cuidadosa. Pode ser promovido a first-party ou removido.

Regra: se a fronteira de design ainda não está clara, é experimental.

---

## O que nunca pertence ao core

- Comparativos com outros frameworks
- Features adicionadas por paridade com Laravel ou qualquer outro framework
- Qualquer subsistema que não passe no filtro:
  - "deixa o framework mais curto?"
  - "deixa o framework mais claro?"
  - "deixa o framework mais observável?"

---

## Decisão de modularização futura

O AI SDK (`core/Ai.php`) é um candidato a ser extraído como pacote Composer independente (`sparkphp/ai`). Enquanto isso não acontece:

- projetos que não usam AI não devem sofrer overhead do SDK
- `core/Ai.php` deve ser carregado sob demanda, não no boot geral

Esta decisão está registrada no roadmap (`TASKS.md`) como item em aberto.

---

## Referência cruzada

- [Maturidade por subsistema](14-releases.md)
- [Contratos de inferência](inference-rules.md)
- [Segurança do Inspector](inspector-security.md)
- [Template core vs avançado](template-core-vs-advanced.md)
