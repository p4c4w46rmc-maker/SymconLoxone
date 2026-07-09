# SymconLoxone Sprint 16

Sprint 16 stabilisiert die bidirektionale Bedienung zwischen IP-Symcon und Loxone.

## Änderungen

- Pushbutton-Aktionen werden als Impuls behandelt und im WebFront automatisch zurückgesetzt.
- Switch-Aktionen senden weiterhin `on` / `off`.
- Neue Zugriffs-Diagnose in Loxone Device zur Prüfung, ob der verwendete Loxone-Benutzer den Control in `LoxAPP3.json` sieht.
- Gateway gibt bei Loxone `403` eine klare Berechtigungsdiagnose aus.
- State-Index im Gateway findet technische State-Variablen nun auch, wenn sie unter `Technik` liegen.
- Debug-Ausgaben für `RequestAction` und `SendControlCommand` verbessert.

## Nach dem Update

1. Dateien ins Repository übernehmen.
2. Commit & Push.
3. In IP-Symcon das Modul aktualisieren.
4. Gateway: `Geräte-Instanzen erzeugen / aktualisieren`.
5. Betroffene Geräteinstanzen einmal öffnen und `Übernehmen`.
6. Bei Problemen im Gerät `Zugriffs-Diagnose` ausführen.

## Hinweis zu 403

Wenn Loxone auf `/jdev/sps/io/<uuidAction>/<command>` mit `403` antwortet, sieht der verwendete API-Benutzer den Control meistens nicht in `LoxAPP3.json`. In Loxone Config muss dann beim Benutzer unter Funktionen der externe Zugriff für Raum, Kategorie und Funktion gesetzt und auf den Miniserver angewendet werden.
