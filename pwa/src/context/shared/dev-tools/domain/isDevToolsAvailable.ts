import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";

/**
 * Whether the dev-tools surface (the `/backoffice/dev-tools` page, the navbar entry
 * point in the frontoffice, and the backoffice sidebar entry) should be
 * exposed at all. Returns `false` in production builds so neither the
 * route nor its entry points reach a real user.
 *
 * Implemented as a function (not a constant) so consumers can call it
 * inside React components without static-analysis tools assuming the
 * value is fixed at module load time. Next inlines `process.env.NODE_ENV`
 * at build time, so the call is effectively zero-cost.
 */
export const isDevToolsAvailable = (): boolean => process.env.NODE_ENV !== NodeEnv.PRODUCTION;
