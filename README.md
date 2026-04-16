# SparkPHP

**Write what matters.**

SparkPHP e um framework PHP file-based, zero-config e observavel por default.
Ele existe para reduzir wiring, cortar boilerplate e deixar o comportamento da
aplicacao visivel no CLI, no Inspector e na propria estrutura de arquivos.

Versao publicada atual: `0.10.0` (`0.10.x`).

---

Dois arquivos. Sem controller. Sem service provider. Sem registro.

```php
// app/routes/users.php
get(fn() => ['users' => User::all()]);
```

```spark
{{-- app/views/users.spark --}}
@title('Usuarios')

@foreach($users as $user)
  <p>{{ $user->name }}</p>
@endforeach
```

Isso e suficiente para:

- `GET /users` com browser → HTML com layout automatico
- `GET /users` com `Accept: application/json` → JSON sem alterar uma linha
- `php spark routes:list` → lista a rota no CLI
- `/_spark` → mostra o request completo no Inspector

---

## O que o Spark otimiza

- **Menos wiring**: nenhum arquivo de registro, nenhum service provider, nenhum Kernel.
- **Mais previsivel**: o arquivo e a convencao dizem o que acontece.
- **Mais observavel**: request, cache, queries, AI e benchmark fazem parte do produto.

## Nucleo do Spark

O runtime base carrega apenas o que e necessario para cada request:

- Router · Request/Response · Middleware · Container
- Template Engine · Database · Model · Validator
- Session · Cache · Logger · Helpers · CLI

O que e core obrigatorio, o que e first-party opcional e o que e experimental:

- [Fronteira do produto](docs/00-product-scope.md)

## SparkPHP vs Laravel

O Spark nao tenta ganhar do Laravel por “ter mais coisas”.

Ele tenta ser melhor em outro eixo:

- **menos wiring para o caso comum**
- **menos superficie para lembrar**
- **mais visibilidade operacional sem setup extra**

Quando o problema pede ecossistema enorme, pacotes first-party maduros e ampla
disponibilidade de time, o Laravel continua excelente.

Quando o problema pede previsibilidade, baixo atrito e um framework que cabe na
cabeca do time, o Spark entra muito forte.

Guia honesto de comparacao:

- [SparkPHP vs Laravel](docs/21-spark-vs-laravel.md)
- [Guia de Adoção](docs/22-adoption-guide.md)
- [Benchmarks](docs/23-benchmarking.md)
- [Migração a partir do Laravel](docs/24-migrating-from-laravel.md)
- [Review Checklist](docs/25-review-checklist.md)

## Quick Start

```bash
composer install
php spark init
php spark serve
```

Ou gere um projeto novo ja com um starter first-party:

```bash
php spark starter:list
php spark new ../meu-saas --starter=saas
```

## Starter kits

O runtime atual publica quatro presets oficiais:

- `api`
- `saas`
- `admin`
- `docs`

Todos continuam sendo Spark puro: rotas em arquivo, templates `.spark`, CLI
versionada, docs em Markdown e observabilidade nativa.

Guia completo:

- [Starter Kits](docs/20-starter-kits.md)

## Documentacao

O indice principal da documentacao fica em:

- [docs/README.md](docs/README.md)

Topicos principais:

- [Instalacao](docs/01-installation.md)
- [Routing](docs/02-routing.md)
- [Request & Response](docs/03-request-response.md)
- [Database](docs/05-database.md)
- [CLI](docs/13-cli.md)
- [AI SDK](docs/16-ai.md)

## Benchmarks e observabilidade

O Spark publica benchmark e diagnostico como partes do produto:

```bash
php spark about
php spark benchmark
php spark inspector:status
```

O objetivo nao e vender microbenchmark isolado. O objetivo e medir ciclo HTTP,
DX e operacao de forma repetivel.

## Maturidade por subsistema

| Subsistema | Categoria | Maturidade |
|---|---|---|
| Routing | Core obrigatorio | Estavel |
| Request / Response | Core obrigatorio | Estavel |
| Middleware | Core obrigatorio | Estavel |
| Container (DI) | Core obrigatorio | Estavel |
| Validator | Core obrigatorio | Estavel |
| Template Engine | Core obrigatorio | Beta |
| Database / Model | Core obrigatorio | Beta |
| Session / Cache | Core obrigatorio | Beta |
| CLI | Core obrigatorio | Beta |
| SparkInspector | First-party opcional | Beta |
| Queue | First-party opcional | Beta |
| Mailer | First-party opcional | Beta |
| Realtime | First-party opcional | Experimental |
| AI SDK | Experimental | Experimental |
| Vector Search | Experimental | Experimental |
| OpenAPI Generator | First-party opcional | Experimental |

- **Categoria** — onde o subsistema pertence na fronteira do produto
- **Maturidade** — garantia de estabilidade da API publica:
  - *Estavel*: contrato fixo; breaking changes apenas em major
  - *Beta*: adequado para producao; API pode mudar em minors com upgrade guide
  - *Experimental*: nao usar em producao sem avaliacao; pode mudar sem aviso

Politica completa: [Releases & Compatibilidade](docs/14-releases.md) · [Fronteira do produto](docs/00-product-scope.md)

## Estado do projeto

O SparkPHP esta na linha `0.x`, ainda consolidando contrato publico. Isso significa:

- a linha ja busca previsibilidade real
- minors ainda podem trazer mudancas estruturais documentadas
- toda release relevante deve atualizar `VERSION`, `CHANGELOG.md` e os guias de upgrade

Mais detalhes:

- [Releases & Compatibilidade](docs/14-releases.md)
- [Upgrade Guide](docs/15-upgrade-guide.md)
- [Contributing](CONTRIBUTING.md)
