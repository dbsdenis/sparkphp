# Realtime Experimental

O SparkPHP publica Realtime como subsistema **first-party opcional** e
**Experimental**.

Escopo atual da v1:

- `SSE` como transporte padrao para servidor -> cliente
- `WebSocket` como worker opcional para fluxos bidirecionais
- discovery file-based em `app/channels/`
- `channel()->join(...)` e `channel()->onMessage(...)`
- auth HTTP por sessao para SSE e token curto para WebSocket
- broker append-only em `storage/realtime/`
- cliente minimo em `public/js/spark-realtime.js`

Guardrails da v1:

- sem presenca formal
- sem ack de entrega confiavel
- sem replay longo
- sem persistencia de mensagens pelo framework

Comandos:

```bash
php spark make:channel chat.[roomId]
php spark realtime:serve
```
