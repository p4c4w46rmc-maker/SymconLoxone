# SymconLoxone

Sprint 10: Binary Value-State Decoder.

Neu:
- Dekodiert Loxone Value-State-Pakete (16 Byte UUID + 8 Byte Double)
- Wandelt Loxone Binary UUIDs in normale UUIDs um
- Verknüpft StateUUID mit dem State-Index
- Schreibt erste Live-Werte direkt in Symcon-Variablen

Testreihenfolge:
1. State-Index erstellen
2. Token Auth testen
3. WebSocket Token Auth testen
4. LiveEngine Decoder Probe
