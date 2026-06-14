/**
 * Named architectural placeholder for the future ABAC policy engine.
 * Intentionally EMPTY — no entries, no logic, no DI, no rule engine. Its only
 * job is to mark the plug-in point so v2 policies attach here instead of leaking
 * into `authorize()`. Do not add behavior without a design change.
 */
export const AccessPolicyRegistry = {} as const;
