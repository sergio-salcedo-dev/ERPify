## Epic 2: Cuentas asociadas (Bank ↔ BankAccount)

Resolver el bloqueo de borrado de bancos con cuentas asociadas (Error 409 `bank-in-use`) mediante una solución de UX basada en la arquitectura DDD, añadiendo la capacidad de ver el número de cuentas asociadas y navegar a la lista de cuentas, manteniendo el backend como la única fuente de verdad.

**FRs covered:** FR1-FR6
**NFRs covered:** Pureza DDD/Hexagonal, Rendimiento / anti-N+1, Consistency timing, Contrato RFC 9457, Paginación keyset cursor-only, Seguridad / PII (IBAN), Coherencia dual-truth + Sentry.
**CE rules:** CE-1 (Un único contrato wire), CE-2 (`DoctrineBankAccountRepository` compartido), CE-3 (Read-model en `BankAccount`), CE-4 (Segregación read/write).

### Story 2.1: API · Read-model batched del contador (PR1) ✅

As a desarrollador de ERPify,
I want añadir `accountCount` a cada item de la lista de bancos con una sola query agregada por página,
So that se exponga la señal de cuentas sin incurrir en problemas de rendimiento N+1.

**Acceptance Criteria:**
- **Given** la lista de bancos
  **When** se solicita la página
  **Then** se incluye `accountCount` en el DTO de respuesta para cada banco (invariante #4, CE-3).
- **Given** la consulta a base de datos
  **When** se evalúa el rendimiento
  **Then** se ejecuta exactamente una query adicional con `GROUP BY bank_id` para obtener los recuentos (anti-N+1).
- **Given** `DoctrineBankAccountRepository`
  **When** se construye la query de cuentas
  **Then** el read-model se aísla en `AccountCountsByBank` del contexto `BankAccount` y no en `Bank` (CE-3, CE-4).
- **Given** la dependencia de keyset
  **When** se integra el PR1
  **Then** se asienta sobre el repositorio `BankAccount` reestructurado del Epic 1 (CE-2).

### Story 2.2: API · Endpoint de cuentas por banco (PR2) ✅

As a desarrollador de ERPify,
I want un nuevo endpoint `GET /backoffice/banks/{id}/accounts` que soporte paginación keyset,
So that la PWA pueda recuperar la lista de cuentas asociadas de forma paginada y segura.

**Acceptance Criteria:**
- **Given** una solicitud a `GET /backoffice/banks/{id}/accounts`
  **When** se recibe la respuesta
  **Then** se usa el envelope FINAL del Epic 1 (CE-1) con `after`/`before` y sin `{cursor, hasMorePages}`.
- **Given** la respuesta del endpoint
  **When** contiene información de la cuenta
  **Then** el IBAN se devuelve íntegro en el payload bajo el grupo `bank_account:read` (invariante #3).
- **Given** los errores 400 (invalid UUID) o 404 (banco ausente)
  **When** ocurren
  **Then** se devuelven usando el pipeline RFC 9457 (invariante #2).
- **Given** una lectura exitosa
  **When** se procesa la solicitud
  **Then** se emite un evento de auditoría de acceso, pero el IBAN NUNCA se registra en los logs.
- **Given** la dependencia de keyset
  **When** se ejecuta el endpoint
  **Then** es servido por `DoctrineSearchEngine` + `PaginationMeta` v2 (Story 1.3).

### Story 2.3: PWA · Read context BankAccount + superficie de cuentas (PR3)

As a usuario del backoffice,
I want una pantalla en la ruta `/backoffice/banks/{id}/accounts` para visualizar la lista de cuentas de un banco,
So that pueda verificar las cuentas (incluyendo el IBAN enmascarado/desenmascarado) antes de realizar una acción de borrado o gestión.

**Acceptance Criteria:**
- **Given** la interfaz de la PWA
  **When** el usuario navega a la nueva ruta
  **Then** se muestra una tabla (reutilizando base components) con Holder, IBAN (enmascarado por defecto con reveal y CopyButton), Alias, Currency y Status.
- **Given** la capa de acceso a datos de la PWA
  **When** se conecta con el backend
  **Then** se inyectan exclusivamente puertos de lectura (CE-4).
- **Given** un error de carga
  **When** falla el endpoint
  **Then** se captura con `AsyncBoundary` y muestra `ProblemDisplay` + `CorrelationIdChip`.

### Story 2.4: PWA · Señales lista + detalle (PR4)

As a usuario del backoffice,
I want ver el número de cuentas asociadas en la tabla de bancos y en la vista de detalle de cada banco,
So that tenga visibilidad inmediata de las dependencias antes de proceder.

**Acceptance Criteria:**
- **Given** la lista de bancos
  **When** se renderiza la tabla
  **Then** se muestra la columna "ACCOUNTS" indicando `accountCount`.
- **Given** la lista de bancos
  **When** `accountCount > 0`
  **Then** el recuento es un enlace clickable a la superficie de cuentas (Story 2.3). Si es `0`, aparece atenuado y no enlaza (invariante #1).
- **Given** la vista de detalle de un banco
  **When** se renderiza
  **Then** muestra "Associated accounts: N · View accounts" o "None" si N es 0.

### Story 2.5: PWA · Delete-guard + recovery de bank-in-use (PR5)

As a usuario del backoffice,
I want que el sistema me advierta amigablemente si intento borrar un banco con cuentas, en lugar de fallar abruptamente o bloquearme,
So that la UX fluya hacia la acción correctiva ("View accounts") reconociendo el backend como la única fuente de verdad.

**Acceptance Criteria:**
- **Given** un intento de borrado en UI
  **When** `accountCount > 0`
  **Then** se muestra un popover neutro con "Can't delete — N associated accounts" y un botón "View accounts", evitando la llamada `DELETE` (flujo optimista, invariante #1).
- **Given** un intento de borrado donde `accountCount` parece ser 0
  **When** el backend devuelve `409 bank-in-use` (condición de carrera)
  **Then** el `mutation-error` persistente incorpora un botón para "View accounts" de recuperación.
- **Given** cualquier estado del frontend
  **When** se intenta forzar el `DELETE /banks/{id}`
  **Then** se reconoce que el backend sigue siendo el guard autoritativo y puede abortarlo.
