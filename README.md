# SymconLoxone Sprint 10.1

Fix: Loxone UUID decoding now uses the native 8-4-4-16 format from LoxAPP3 instead of RFC4122 8-4-4-4-12 formatting.

Expected result: Known StateUUIDs should now increase and matching live values should be written to Symcon variables.
