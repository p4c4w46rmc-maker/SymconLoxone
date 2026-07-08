# SymconLoxone

Sprint 12: WebFront- und Visualisierungsintegration.

## Neu

- Technische Metadaten und rohe State-Variablen werden versteckt.
- Loxone Device erzeugt sichtbare Bedien-/Statusvariablen:
  - Switch: `Schalter`
  - Pushbutton: `Auslösen`
  - InfoOnlyDigital: `Status`
  - NFC/Access: `Gesperrt`, `Gerätestatus`, `Letzter Benutzer`, `Letzter Tag`, `Letzter Code`
- LiveEngine aktualisiert zusätzlich zu den Roh-States auch die WebFront-Variablen.
- Schalter und Pushbuttons sind direkt in Symcon bedienbar.

## Test

1. Module aktualisieren.
2. Gateway öffnen und `Geräte-Instanzen erzeugen / aktualisieren` ausführen.
3. Eine Device-Instanz öffnen und `Übernehmen` klicken, falls sie bereits existierte.
4. `LiveEngine Decoder Probe` ausführen.
5. Im WebFront sollten die neuen Bedienvariablen sichtbar sein.
