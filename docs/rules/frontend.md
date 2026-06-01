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
