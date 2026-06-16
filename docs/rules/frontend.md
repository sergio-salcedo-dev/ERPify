# Frontend Guidelines

> **ERPify frontend work is governed by [`../../pwa/DESIGN.md`](../../pwa/DESIGN.md)** — the enterprise-first UX
> philosophy, persona, UI review mandate, design-system contract (tokens, composites, patterns), and accessibility non-negotiables. That document is authoritative; the cross-project baseline below applies underneath it.

## HTML
- Use semantic HTML5 elements
- Ensure accessibility (ARIA labels, alt text, proper heading hierarchy)
- Validate HTML markup
- Keep HTML structure clean and minimal

## CSS
- Use consistent naming conventions (BEM, CSS Modules, etc.)
- Avoid inline styles when possible
- Use CSS variables for theming
- Ensure responsive design
- Follow mobile-first approach

## JavaScript/TypeScript
- Use modern ES6+ features
- Prefer const/let over var
- Use arrow functions when appropriate
- Avoid global variables
- Handle errors properly
- Use async/await instead of callbacks when possible

### Consuming enum-like constants: `Record`, compare, or `switch`

Closed sets of values are modelled as const-objects (`UserStatus`, `Theme`, `AuthStatus`, `SystemStatus`, …) — never bare string-literal unions or magic strings. How you *consume* one depends on what you do with it:

| You use the constant to…                                                                   | Use                                                                                          | Why                                                                                                                                              |
| ------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| **map each member to a value** (label, CSS class, icon, badge variant, numeric weight)     | a module-scope `const X: Record<TheEnum, T>` with computed keys (`[TheEnum.A]: …`)            | Exhaustive by construction — adding a member fails the build until it is mapped; O(1) lookup; no recreated-per-render cost. Precedent: `ROLE_LABEL`, `STATUS_LABEL`, `TONE_ICON`, `SEVERITY`, the roadmap `STATUS_*` maps. |
| **branch / guard** (`if (status === X)`, early return)                                     | compare against the constant (`status !== AuthStatus.UNAUTHENTICATED`)                        | There is no per-member value to map *to* — a `Record` would be noise. Precedent: `RequireAuth`, `authorize`.                                     |
| **run different logic per member** (several statements, side effects, JSX built from closures) | `switch` / `if`–`else`                                                                    | Cases are behaviour, not values; a `Record` can't hold closures cleanly and obscures control flow. Precedent: `bankRealtime` parse, the delete-error action switches. |

**Rule of thumb:** `Record<Enum, T>` for value lookups, comparison for branches, `switch` for logic.

Watch for the anti-pattern: a `switch` that only `return`s a constant value per case and leans on `default:` to cover the remaining member(s). That `default` silently mislabels any member added later — replace it with an exhaustive `Record<Enum, T>` (matches the file's own pattern wherever a sibling `Record` already exists).

## Performance
- Minimize HTTP requests
- Optimize images and assets
- Use lazy loading for images and content
- Minimize JavaScript bundle size
- Use CDN for static assets when appropriate

## Accessibility
- Ensure keyboard navigation works
- Provide proper focus indicators
- Use semantic HTML
- Test with screen readers
- Maintain proper color contrast ratios
