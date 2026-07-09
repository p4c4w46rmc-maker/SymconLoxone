# SymconLoxone – Sprint 17

Sprint 17 erweitert die stabile Sprint-16-Basis um einen automatisierbaren LiveEngine-Zyklus.

## Neu

- LiveEngine-Zyklus als Gateway-Funktion: `LOX_RunLiveEngineCycle($id)`
- optionales Gateway-Feld **LiveEngine-Zyklus Sekunden** (`0 = aus`)
- Schutz gegen überlappende LiveEngine-Zyklen
- neue Gateway-Statusvariablen:
  - LiveEngine Automatik
  - LiveEngine angewendete Werte
  - Letztes Kommando
- bessere Rückmeldung bei gesendeten Befehlen
- WebFront-/Device-Funktionen aus Sprint 16 bleiben erhalten

## Testreihenfolge

1. Module aktualisieren
2. Gateway öffnen und **Geräte-Instanzen erzeugen / aktualisieren** ausführen
3. **State-Index erstellen** ausführen
4. **LiveEngine Automatik-Zyklus testen** ausführen
5. Bei Erfolg optional `LiveEngine-Zyklus Sekunden` setzen, z. B. 30 oder 60 Sekunden

Hinweis: Der LiveEngine-Zyklus öffnet aktuell pro Zyklus eine WebSocket-Verbindung und wertet Liveframes aus. Das ist bewusst konservativ und stabil. Der dauerhaft offene WebSocket-Listener bleibt der nächste große Schritt.
