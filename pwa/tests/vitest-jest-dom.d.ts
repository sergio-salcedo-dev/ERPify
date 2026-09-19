// jest-dom augments `Assertion<T>`, while vitest declares `Assertion<R, T>`. The
// arities differ, so declaration merging never fires: every matcher works at
// runtime and is invisible to tsc. `Matchers<R, T>` is vitest's own empty
// extension point, which `Assertion`, `ExpectStatic` and
// `AsymmetricMatchersContaining` all extend, so augmenting it reaches every call
// site. Drop this file once @testing-library/jest-dom augments the two-parameter
// form itself.
import type { TestingLibraryMatchers } from "@testing-library/jest-dom/matchers";

declare module "vitest" {
  // The type parameters restate vitest's own constraint and default verbatim.
  // Merging tolerates a mismatch only because `skipLibCheck` hides TS2428, so
  // simplifying this signature reds the moment that flag is turned off.
  // An empty body is what interface-extension augmentation is: the members come
  // from the supertype and declaration merging carries them onto vitest.
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  interface Matchers<
    R extends void | Promise<void> = void | Promise<void>,
    T = unknown,
  > extends TestingLibraryMatchers<T, R> {}
}
