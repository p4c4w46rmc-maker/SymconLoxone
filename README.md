# SymconLoxone

Sprint 7.2: WebSocket handshake diagnostic.

## Neu

- WebSocket-Handshake-Test gegen `/ws/rfc6455`
- prüft TCP/TLS-Verbindung, HTTP-Upgrade und Basic-Auth-Header
- Statusvariablen für WebSocket-URL und letzten Handshake
- noch kein permanenter Live-Listener; dieser folgt nach erfolgreichem 101-Upgrade-Test
