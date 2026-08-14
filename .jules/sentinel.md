# Sentinel's Journal - Critical Security Learnings

## 2025-10-31 - Missing Rate Limiting on Authentication Endpoints and Ignored Tests
**Vulnerability:** The `/login` and `/api/login` authentication endpoints, as well as the public `/api/echo/heartbeat` endpoint, completely lacked rate-limiting (throttling) protections, making them highly vulnerable to brute-force credential stuffing and denial-of-service (DoS) spam. Furthermore, the entire `/tests/` directory was ignored in `.gitignore`, preventing the deployment and tracking of automated test suites.
**Learning:** These endpoints were initially scaffolded without standard middleware configuration because the application's test suite was entirely uncommitted/ignored by the repository setup, leading to a gap where security controls could not be continuously checked.
**Prevention:** Always enforce standard rate-limiting middleware (`throttle:X,Y`) on sensitive authentication/POST endpoints. Unignore the `/tests/` directory in `.gitignore` to ensure automated test suites are tracked, executed, and enforced in CI/CD pipelines.
