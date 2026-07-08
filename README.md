# SymconLoxone Sprint 14 – Visualisierungs-Root

Ziel: Die importierten Loxone-Geräte werden nicht mehr unter der Gateway-Instanz angelegt, sondern unter einer Root-Kategorie `Loxone` direkt im Objektbaum.

Dadurch ist die Struktur im WebFront sichtbar, ohne dass eine Instanz vor dem Ordner liegt.

## Änderungen

- `ImportControls()` verwendet künftig `GetVisualizationRootCategoryId()` statt `$this->InstanceID` als Root.
- `ImportDeviceInstances()` verwendet künftig dieselbe Root-Kategorie.
- Bereits vorhandene Loxone-Device-Instanzen werden anhand `GatewayID + ControlUUID` gesucht und in die neue Struktur verschoben, statt doppelt erzeugt.
- Die alte Struktur unter der Gateway-Instanz wird nicht automatisch gelöscht. Erst testen, dann manuell entfernen.

## Anwendung

Die Datei `sprint14-root-visualization.patch` im lokalen Repository anwenden oder die Änderungen manuell in `LoxoneGateway/module.php` übernehmen.

Danach:

1. Commit & Push
2. In IP-Symcon Module aktualisieren
3. Loxone Gateway öffnen
4. `Geräte-Instanzen erzeugen / aktualisieren`
5. WebFront neu laden

