# Deferred work

## Blocked on the real auth API (iam-user-management-frontend, mocked)

These two need the real authentication backend; implementing them against the
pure mocks would be dead scaffolding (no server violations to map, no token to
validate), against "minimum code, nothing speculative".

- Auth forms (login/register/forgot/reset) lack the server-violation `setError(field,{type:"server"})` seam. Pure mocks emit no violations, so it would be dead now. Wire it when the real auth API lands (`UserForm` has the pattern).
- Reset-password opaque-token validation: the form already reads the token under a Suspense boundary and rejects a missing token; validating a present token (invalid/expired → boundary) needs the real auth API.
