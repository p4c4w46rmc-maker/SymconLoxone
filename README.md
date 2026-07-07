# SymconLoxone

Loxone integration for IP-Symcon.

## Status

Sprint 3: installable gateway module with basic Miniserver readout and first object tree import.

## Sprint 3 features

- Reads `/data/LoxAPP3.json`
- Creates a `Loxone` category below the gateway instance
- Imports rooms
- Imports categories
- Imports controls grouped by room
- Stores control metadata and state UUIDs

This is still an import prototype. Live values and control commands will follow in later sprints.
