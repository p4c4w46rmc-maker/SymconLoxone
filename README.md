# SymconLoxone

Loxone integration for IP-Symcon.

## Sprint 13 – Geräte-Engine / Smart WebFront

Neu in Sprint 13:

- Geräteklassifizierung direkt in `LoxoneDevice`
- bessere WebFront-Beschriftungen für Tor, Zutritt, Alarm, NFC und Schaltuhr
- Schaltuhren werden weniger technisch dargestellt
  - `Aktiv` statt generischem `Wert`
  - `Modus`/interne Werte werden aus der Visualisierung ausgeblendet
- Garagentore/Tore bekommen passendere Bedien- und Statusnamen
  - `Tor auslösen`
  - `Torstatus`
- Zutritt/Türen bekommen passendere Statusnamen
  - `Türstatus`
  - `Öffnen / Auslösen`
- Alarm-Pushbuttons bekommen sprechende Aktionen
  - `Scharf schalten`
  - `Unscharf schalten`
- NFC/Access zeigt nur die relevanten Bedien- und Statuswerte
- bestehende `Display_*`-Variablen werden beim Übernehmen automatisch umbenannt/versteckt

## Nach dem Einspielen

1. Modul aktualisieren
2. Loxone Gateway öffnen
3. Geräte-Instanzen erzeugen / aktualisieren
4. Bei bestehenden Loxone-Device-Instanzen einmal **Übernehmen** klicken, falls alte Anzeigen wie `Modus`/`Wert` noch sichtbar sind
5. WebFront neu laden

## Stand

- HTTP API: funktioniert
- LoxAPP3 Import: funktioniert
- Geräte-Import: funktioniert
- Token Auth: funktioniert
- WebSocket Auth: funktioniert
- LiveEngine Decoder: funktioniert
- Erste Steuerbefehle: funktioniert
- Sprint 13: Smart WebFront / Geräteklassifizierung
