# SymconLoxone

A modern Loxone integration for IP-Symcon.

## Status

Version 0.4: Import Engine.

## Features in 0.4

- Native IP-Symcon module structure
- Loxone Gateway instance
- Host, port, HTTPS, username and password configuration
- Basic connection test against `/jdev/cfg/version`
- Basic LoxAPP3 analysis against `/data/LoxAPP3.json`
- Object tree import
- Control import grouped by room and category
- Typed state variables for common Loxone states
- Metadata variables for UUID, room, category and control type

## Next

- Live state updates via WebSocket
- Command support for Pushbutton, Switch, Light and Jalousie
