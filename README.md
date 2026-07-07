# SymconLoxone

Sprint 9: WebSocket LiveEngine Probe.

## Neu

- LiveEngine Probe starten
- authentifiziert per WebSocket Token
- sendet `jdev/sps/enablebinstatusupdate`
- liest Text- und Binary-Frames für ein kurzes Diagnosefenster
- zeigt Frame-Zahlen, Opcode, Länge und binäre Header-Informationen

## Testreihenfolge

1. State-Index erstellen
2. Token Auth testen
3. WebSocket Token Auth testen
4. LiveEngine Probe starten

Dieser Sprint ist noch ein begrenzter Diagnose-Listener. Der nächste Schritt ist die dauerhafte, nicht-blockierende Verarbeitung und Zuordnung der Binärwerte auf Symcon-Variablen.
