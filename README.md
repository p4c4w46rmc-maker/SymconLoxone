# SymconLoxone

A modern Loxone integration for IP-Symcon.

## Status

Version 0.5: Device instance architecture.

## Features in 0.5

- Native IP-Symcon module structure
- Loxone Gateway instance
- Host, port, HTTPS, username and password configuration
- Basic connection test against `/jdev/cfg/version`
- LoxAPP3 analysis against `/data/LoxAPP3.json`
- Control import grouped by room and category
- Typed state variables for common Loxone states
- New `Loxone Device` module
- Gateway can create/update one device instance per Loxone control
- Device instances store UUID, action UUID, room, category, control type and states

## Next

- Configurator UI for selectable import
- Live state updates via WebSocket
- Command support for Pushbutton, Switch, Light and Jalousie
