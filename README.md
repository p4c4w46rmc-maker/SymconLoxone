# SymconLoxone

Sprint 7.1: LiveEngine foundation cleanup.

## Neu

- State-Index bleibt aktiv: `Loxone State UUID -> Symcon VariableID`
- HTTP-Snapshot filtert States, die Loxone über `/dev/sps/io` nicht direkt liefert
- weniger 404-Meldungen bei Daytimer/NFC/komplexen States
- WebSocket-Transport-Scaffold vorbereitet

## Nächster Schritt

Token-/Session-Handshake und echter WebSocket-Empfang für Live-Werte.
