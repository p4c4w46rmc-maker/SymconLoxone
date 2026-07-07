# SymconLoxone

Sprint 8: Token Authentication foundation.

## New

- LoxoneAuth helper class
- getkey2 parsing
- password hash creation using SHA1/SHA256 from Miniserver response
- HMAC login hash creation
- legacy token diagnostic request
- WebSocket auth command diagnostic

## Test order

1. Token Auth testen
2. WebSocket Token Auth testen

If the legacy token endpoint is rejected by the Miniserver, the next sprint will add encrypted JWT command support.
