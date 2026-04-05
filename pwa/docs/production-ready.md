# Production Ready Checklist for Erpify

This document outlines the steps and best practices to deploy Erpify to a production environment with a focus on security, performance, and reliability.

For **secure production deployment** (TLS, CORS allowlists, secrets, Docker): see [production-deployment.md](./production-deployment.md).

## 1. Environment Configuration

- [ ] **Secrets Management**: Ensure all sensitive information (API keys, database credentials) are stored in a secure environment variable manager (e.g., Vercel Environment Variables, AWS Secrets Manager).
- [ ] **NEXT*PUBLIC* Prefix**: Only prefix variables with `NEXT_PUBLIC_` if they are absolutely necessary for the client-side. Keep all other secrets server-side.
- [ ] **API Key Rotation**: Implement a strategy for rotating API keys periodically.

## 2. Security Best Practices

- [ ] **HTTPS Only**: Ensure the production environment enforces HTTPS.
- [ ] **Content Security Policy (CSP)**: Implement a strict CSP to mitigate XSS and data injection attacks.
- [ ] **Dependency Audit**: Regularly run `npm audit` to check for vulnerabilities in third-party packages.
- [ ] **Rate Limiting**: Implement rate limiting on API routes to prevent brute-force and DoS attacks.
- [ ] **Input Validation**: Use libraries like `zod` or `yup` to validate all incoming data on the server-side.

## 3. Performance Optimization

- [ ] **Image Optimization**: Use the Next.js `<Image />` component for automatic image resizing and optimization.
- [ ] **Caching Strategy**: Implement appropriate caching headers for static assets and API responses.
- [ ] **Bundle Analysis**: Use `@next/bundle-analyzer` to identify and reduce large dependencies in the client bundle.
- [ ] **Edge Runtime**: Consider using the Edge Runtime for latency-sensitive API routes.

## 4. Deployment Steps

1. **Build**: Run `npm run build` to generate the production-ready bundle.
2. **Test**: Execute all unit tests (`npm test`) and E2E tests (`npm run e2e`) in a CI/CD pipeline.
3. **Lint**: Ensure all code passes linting (`npm run lint`) and formatting checks.
4. **Deploy**: Push to your preferred hosting provider (Vercel, Netlify, or a custom VPS).
5. **Post-Deployment Check**: Verify that all core functionalities (Landing Page, Backoffice, API Health Checks) are working as expected in the live environment.

## 5. Monitoring & Logging

- [ ] **Error Tracking**: Integrate a tool like Sentry to capture and report runtime errors.
- [ ] **Analytics**: Use privacy-focused analytics (e.g., Plausible, Fathom) to track user engagement.
- [ ] **Log Management**: Centralize logs for easier debugging and auditing.

## 6. Hexagonal Architecture Maintenance

- [ ] **Layer Integrity**: Ensure that the Domain layer remains pure and free of infrastructure concerns.
- [ ] **Dependency Injection**: Maintain the `Container.ts` for clean dependency management and easier testing.
- [ ] **Shared Kernel**: Keep common logic centralized in `src/context/shared` to avoid duplication.
