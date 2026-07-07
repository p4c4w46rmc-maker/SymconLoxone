# SymconLoxone

A modern Loxone integration for IP-Symcon.

## Status

Version 0.6: first state refresh implementation.

## Features so far

- Native IP-Symcon module structure
- Loxone Gateway instance
- Loxone Device instances
- Basic connection test against `/jdev/cfg/version`
- Basic LoxAPP3 analysis against `/data/LoxAPP3.json`
- Import rooms, categories and controls
- Create one device instance per Loxone control
- Register state variables per control
- Refresh state values via Loxone IO webservice
- Optional polling interval on gateway

## Next

- Replace polling with persistent WebSocket live updates
- Add control actions for Switch, Pushbutton, gates, alarm, shading and lights
