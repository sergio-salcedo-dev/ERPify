# Deferred work

## Deferred from: code review of story-2.3 (2026-06-13)

- El guard de unión cerrada de `ApiBankAccountRepository` (`isBankAccountPrimitives`, líneas 988-1002) hace fallar la página entera con `malformed-response-envelope` si el backend añade una `currency` ≠ EUR o un 4º `status`. Es deliberado por CE-1 (un único contrato wire), pero la superficie de error es frágil: un cambio de contrato versionado tumba la pantalla en vez de degradar por fila. Reconsiderar cuando se amplíe el set de monedas/estados.

## Deferred from: code review of 2-4-pwa-senales-lista-mas-detalle-pr4 (2026-06-13)

- **Write-path `accountCount: 0`** — `ApiBankRepository.create`/`update` defaultean `accountCount` a `0` (POST/PUT omiten `GROUP_ACCOUNT_COUNT`); un banco con cuentas devuelve "0/None" hasta el refetch. Latente: seguro hoy solo porque todo consumidor refetchea. `ApiBankRepository.ts:538,549`.
- **Sin clamping de `accountCount` malformado** — los type-guards (`ApiBankRepository.ts`, `bankRealtime.ts`) aceptan `NaN`/negativo/no-entero (`typeof NaN === "number"`), que renderiza como "None" en vez de rechazarse. Falta `Number.isInteger` + `>= 0`. Depende de una violación de contrato del API que hoy no ocurre.
