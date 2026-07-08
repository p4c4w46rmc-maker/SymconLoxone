# SymconLoxone Sprint 17 – Control-Inspector

Dieser Sprint ist bewusst als sicherer Einfüge-Patch vorbereitet, damit die bestehende `LoxoneDevice/module.php` aus Sprint 16.5 nicht überschrieben wird.

## Dateien

- `LoxoneDevice/form.json` kann vollständig ersetzt werden.
- `snippets/LoxoneDevice_methods_to_insert.php.txt` enthält neue Methoden für `LoxoneDevice/module.php`.
- `snippets/LoxoneGateway_method_to_insert.php.txt` enthält eine neue Methode für `LoxoneGateway/module.php`.

## Einbau

1. `LoxoneDevice/form.json` ersetzen.
2. Inhalt aus `snippets/LoxoneDevice_methods_to_insert.php.txt` in `LoxoneDevice/module.php` innerhalb der Klasse einfügen, am besten nach `ActionDiagnostics()`.
3. Inhalt aus `snippets/LoxoneGateway_method_to_insert.php.txt` in `LoxoneGateway/module.php` innerhalb der Klasse einfügen, am besten nach `ReadMiniserver()`.
4. Commit & Push.
5. Symcon Module aktualisieren.
6. Gerät öffnen und `Control-Inspector` ausführen.

## Ziel

Der Inspector zeigt:

- ControlUUID
- ActionUUID
- Typ
- Geräteklasse
- StatesJson
- DetailsJson
- vollständige Control-Definition aus der aktuellen LoxAPP3

Damit finden wir heraus, ob bei Pushbutton/Tor/Zutritt eine andere UUID oder ein anderer Befehl benutzt werden muss.
