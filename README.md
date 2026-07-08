# SymconLoxone Sprint 15.5

Ziel: Bedienvariablen (Auslösen, Tor auslösen, Scharf schalten usw.) werden direkt unter der Geräteinstanz angezeigt, damit sie im WebFront sichtbar und klickbar sind.

Dieses Paket enthält einen Patch für `LoxoneDevice/module.php`.

## Anwendung

1. Datei `sprint15_5-webfront-control-flat.patch` in dein lokales Repository kopieren.
2. Im Terminal im Repository ausführen:

```bash
git apply sprint15_5-webfront-control-flat.patch
```

3. Commit & Push:

```bash
git add LoxoneDevice/module.php
git commit -m "Sprint 15.5 flatten WebFront control variables"
git push
```

4. In IP-Symcon:
   - Module aktualisieren
   - Geräte-Instanzen erzeugen/aktualisieren
   - Bestehende Geräteinstanzen einmal „Übernehmen“
   - WebFront neu laden

## Wirkung

Vorher:

```text
Gerät
└── Bedienung
    └── Auslösen
```

Nachher:

```text
Gerät
├── Auslösen
└── Status
    └── Gesperrt
```

`Status`, `Informationen` und `Technik` bleiben als Kategorien erhalten.
