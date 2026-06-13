---
name: ERPify Backoffice — Cuentas asociadas
description: Delta visual de "cuentas asociadas" sobre el contrato base ux-ERPify-2026-06-03 (Shadcn + tokens --erpify-*). Solo añade los componentes nuevos; no redefine la identidad.
status: draft
updated: 2026-06-10
spacing:
  # Hereda los presupuestos del base. Nuevos presupuestos de contención:
  col-accounts: 72px        # columna numérica de contador en la lista (decidido)
  col-accounts-breakpoint: 'lg'   # se oculta por debajo, como Updated/Created (decidido)
components:
  account-count-cell:
    align: 'right'
    type: 'meta'            # 12px, regla de contraste meta-text-rule del base
    zeroColor: '--erpify-text-subtle'   # excepción al meta-rule: el 0 es decorativo/no accionable [ASSUMPTION]
    linkColor: '--erpify-brand'         # solo cuando N>0 (es enlace)
  associated-accounts-field:
    surface: 'detalle (dl/dd)'
    note: 'valor N + enlace "View accounts" (brand); 0 → "None" en text-muted, sin enlace'
  accounts-table:
    inherits: 'components.table / table-row / status-badge / truncated-cell (base)'
    columns: 'Holder(flex, truncate) · IBAN(iban-field) · Alias · Currency · Status(StatusBadge)'
  iban-field:
    fontFamily: 'Geist Mono'
    fontSize: 12px
    maskedFormat: 'ES•• ···· 1234'      # país + ····  + últimos 4
    revealToggle: 'ojo lucide 16px, ghost (= row-action-button del base), aria-pressed'
    copy: 'CopyButton existente (icono copy 16px) — copia el valor íntegro siempre'
    autoHide: '~10s o al perder foco/hover (decidido)'
    motion: 'sin fade al revelar bajo prefers-reduced-motion (regla base)'
  delete-guard:
    trigger: 'acción Delete cuando accountCount > 0'
    surface: 'Popover Shadcn no destructivo (neutral, NO danger), anclado al control (decidido)'
    content: 'texto + enlace "View accounts" (Button ghost, brand)'
    note: 'NO abre el Dialog destructivo; el Delete con N=0 conserva el confirm danger del base'
---

## Brand & Style

Sin cambios de identidad: hereda la postura "herramienta, no escaparate" y el delta de **contención** del contrato base [`ux-ERPify-2026-06-03`](imports/ux-ERPify-2026-06-03-DESIGN.md). Esta iteración añade una señal numérica (contador), una superficie de datos densa más (cuentas) y un control de **revelado tipo contraseña** para PII (IBAN). Regla heredada: *el layout manda, el contenido se adapta, el valor completo a un clic/focus*.

## Colors

Paleta heredada sin cambios. Uso nuevo:

- **Contador de cuentas**: `N>0` es **enlace** → `--erpify-brand` en hover/focus, `--erpify-text` en reposo. `0` no accionable → atenuado (`account-count-cell.zeroColor`) `[ASSUMPTION]` (excepción consciente al meta-rule por ser cifra decorativa; si molesta, sube a `text-muted`).
- **Delete-guard**: superficie **neutra**, NO `--erpify-danger` — no es un error ni una acción destructiva, es una explicación. El rojo se reserva al confirm destructivo real (N=0) y al `mutation-error` (base).
- **IBAN**: enmascarado e íntegro ambos en `--erpify-text` (≥AA); el ojo y el copy heredan el tono de `row-action-button`.

## Typography

- **`account-count-cell`** y **`associated-accounts-field`** usan `meta` (12px) con la regla de contraste del base (suelo `text-muted`).
- **`iban-field`** usa **Geist Mono 12px** (como `entity-code`): el mono da ancho predecible y lee "identificador máquina" — apropiado para un IBAN, enmascarado o no.

## Components

Hereda primitivas Shadcn + `erpify/` + todo el delta del base. Nuevo:

- **Contador de cuentas (lista)** (`{components.account-count-cell}`) — celda numérica alineada a la derecha en la columna `{spacing.col-accounts}`, situada tras Status; se oculta por debajo de `{spacing.col-accounts-breakpoint}` (como Updated/Created). `N>0` es un enlace (`--erpify-brand` en hover/focus) a la superficie de cuentas; `0` atenuado y no interactivo. No introduce un canal de color como única señal (es una cifra).
- **Campo "Associated accounts" (detalle)** (`{components.associated-accounts-field}`) — fila del dl/dd meta: label neutro + `N` + enlace "View accounts"; `0` → "None" en `text-muted`. No compite con el H1.
- **Tabla de cuentas** (`{components.accounts-table}`) — reutiliza `{components.table}`/`table-row`/`status-badge`/`truncated-cell` del base sin delta de identidad; única columna flexible = Holder (truncate + tooltip-si-truncado). Cabecera sticky, densidad y focus de fila heredados.
- **Campo IBAN — revelar tipo contraseña** (`{components.iban-field}`) — Geist Mono 12px. Estado por defecto **enmascarado** (`maskedFormat`); **toggle ojo** (lucide `eye`/`eye-off` 16px, ghost = `row-action-button` del base, `aria-pressed`) revela el valor íntegro y se re-enmascara solo (`autoHide`); **CopyButton** (icono copy 16px) copia el valor íntegro siempre. Sin fade al revelar bajo `prefers-reduced-motion`. El degradado/máscara es CSS; el string íntegro solo se pinta al revelar.
- **Delete-guard** (`{components.delete-guard}`) — cuando `accountCount > 0`, la acción Delete abre un **Popover Shadcn neutro** (no `Dialog`, no `danger`) anclado al control: texto "Can't delete — N associated accounts" + Button ghost "View accounts" (`--erpify-brand`). Visualmente es informativo, no alarmante. Con `N=0` se conserva el `Dialog` destructivo del base sin cambios.

## Do's and Don'ts

| Do | Don't |
|---|---|
| Contador como cifra con presupuesto de columna fijo; enlace solo si N>0 | Codificar "tiene cuentas" solo por color; columna que se ensancha con el número |
| IBAN enmascarado por defecto; revelar momentáneo + copy, ambos focusables/touch | Mostrar IBAN íntegro por defecto; revelar solo-hover sin equivalente teclado |
| Guard en superficie **neutra** que explica y ofrece "View accounts" | Guard en rojo/`danger`, o abrir el confirm destructivo para decir "no puedes" |
| Reutilizar `{components.table}` y tokens del base para la tabla de cuentas | Forkear un segundo sistema de tabla/tokens para cuentas |
| Mono para el IBAN (enmascarado o no) | Proporcional para identificadores; tamaños < 11px |
