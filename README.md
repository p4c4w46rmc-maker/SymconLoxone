# SymconLoxone

Sprint 11: Erste bidirektionale Steuerung.

## Neu

- Loxone Device kann Standardbefehle senden
- Pushbutton: `pulse`
- Switch: `on`, `off`, `pulse`
- Gateway-Methode `LOX_SendControlCommand()`
- Device-Methoden `LOXD_Press()`, `LOXD_SwitchOn()`, `LOXD_SwitchOff()`, `LOXD_Toggle()`
- Bei Switch/Pushbutton wird die Variable `active` als Aktion aktiviert

## Test

1. Modul aktualisieren
2. Geräte-Instanzen prüfen
3. Einen ungefährlichen Pushbutton/Switch auswählen
4. In der Device-Instanz auf **Standardbefehl testen** klicken

Hinweis: Garagentore/Alarm bitte erst testen, wenn klar ist, welches Control ausgelöst wird.
