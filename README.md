# SymconLoxone

Sprint 7: LiveEngine foundation.

## Neu in Sprint 7

- State-Index: Loxone State UUID → Symcon VariableID
- Gateway-Variablen für LiveEngine-Status
- Button **State-Index erstellen**
- Button **LiveEngine Test: Indexed Refresh**
- zentrale Methode `ApplyLiveStateValue($stateUuid, $rawValue)` als Grundlage für WebSocket-Ereignisse

Hinweis: Der echte WebSocket-Transport wird im nächsten Schritt auf diese State-Index-Schicht gesetzt. Sprint 7 validiert zuerst die zentrale Zuordnung und Aktualisierung aller State-Variablen.
