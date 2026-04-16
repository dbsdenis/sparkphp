# Realtime

O SparkPHP publica Realtime como subsistema **first-party opcional** e
**Experimental**.

A proposta da v1 e hibrida:

- `SSE` como caminho simples para servidor -> cliente
- `WebSocket` como worker opcional para fluxos bidirecionais
- discovery file-based em `app/channels/`
- broker append-only em `storage/realtime/`

## Quando usar

Realtime faz sentido quando voce quer:

- chat simples por sala
- notificacoes ao vivo
- dashboards atualizando sem refresh
- eventos de background refletidos na UI

Se o caso for apenas "atualizar a tela quando algo acontecer", prefira `SSE`.
Se o cliente precisa enviar mensagens em tempo real, suba o worker WebSocket.

## Canal file-based

Crie um canal em `app/channels/`:

```bash
php spark make:channel chat.[roomId]
```

Exemplo:

```php
<?php

channel()
    ->join(function ($roomId) {
        if (!auth() || (string) $roomId !== '42') {
            return false;
        }

        return [
            'id' => auth()->id,
            'room' => $roomId,
        ];
    })
    ->onMessage('message.send', function (array $payload, $roomId) {
        realtime()->broadcast("chat.{$roomId}", 'message.sent', [
            'body' => $payload['body'] ?? null,
            'user_id' => auth()->id,
        ]);

        return ['stored' => true];
    });
```

Regras da v1:

- `channel()->join(...)` autoriza a entrada no canal
- `channel()->onMessage(...)` trata mensagens vindas do WebSocket
- `realtime()->broadcast(...)` publica o envelope para SSE e WebSocket

## SSE

Para receber eventos no browser, use a URL do stream:

```php
$url = realtime()->sseUrl('chat.42');
```

Ou direto no cliente:

```js
const stream = SparkRealtime('/_realtime/stream?channel=chat.42');

stream
  .on('message.sent', (envelope) => {
    console.log(envelope.payload.body);
  })
  .connect();
```

O cliente minimo oficial fica em:

- `public/js/spark-realtime.js`

O stream SSE envia:

- envelopes JSON com `id`, `channel`, `event`, `payload`, `meta`, `created_at`
- `:heartbeat` periodico para evitar timeout de proxy
- suporte a retomada com `Last-Event-ID`

## Auth para WebSocket

O worker WebSocket nao depende de `$_SESSION` diretamente. O fluxo da v1 e:

1. a request HTTP autenticada chama `POST /_realtime/auth`
2. o Spark emite um token curto assinado com `APP_KEY`
3. o cliente usa esse token no subscribe do WebSocket
4. o worker valida o token offline e reaplica `channel()->join(...)`

Exemplo de resposta do auth:

```json
{
  "token": "...",
  "channel": "chat.42",
  "expires_in": 30,
  "ws_url": "ws://localhost:8081/_realtime/ws"
}
```

## Worker WebSocket

Suba o worker:

```bash
php spark realtime:serve
```

Com porta customizada:

```bash
php spark realtime:serve --host=127.0.0.1 --port=8082
```

Escopo atual do worker:

- handshake
- subscribe com token curto
- recebimento de JSON
- dispatch para `channel()->onMessage(...)`
- fan-out dos envelopes persistidos no broker

## Variaveis de ambiente

```env
REALTIME_PREFIX=/_realtime
REALTIME_WS_PORT=8081
REALTIME_TOKEN_TTL=30
REALTIME_GC_TTL=300
```

## Limites da v1

Nesta fase experimental, o Spark **nao** entrega:

- presenca formal
- typing indicators nativos
- ack de entrega confiavel
- replay longo
- persistencia de mensagens pela framework

A persistencia do dominio continua sendo responsabilidade da aplicacao.
