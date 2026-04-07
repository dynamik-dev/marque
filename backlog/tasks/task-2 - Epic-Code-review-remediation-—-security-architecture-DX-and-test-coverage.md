---
id: task-2
title: 'Epic: Code review remediation — security, architecture, DX, and test coverage'
status: In Progress
assignee: []
created_date: '2026-04-07 18:31'
labels:
  - epic
dependencies: []
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Comprehensive code review identified 36 findings across security, architecture, developer experience, and test coverage. 21 tickets across 7 waves.

Wave 1 (parallel): Security hardening — fail-closed middleware, input validation, wildcard matcher fix, import morph validation
Wave 2 (parallel): Contract architecture — add methods to contracts, extract hasRole(), fix exporter, fix effectivePermissions()
Wave 3 (parallel): DX polish — fix README, remove deprecation confusion, rename roles(), scoped cache clear
Wave 4 (parallel): Behavioral consistency — RoleMiddleware global assignments, boundary docs, table prefix, TOCTOU docs
Wave 5 (parallel): Code quality — extract shared utilities, dead code cleanup
Wave 6 (parallel): Test coverage — security test gaps, edge case tests
Wave 7 (sequential): Final integration review
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 All sub-tickets completed and reviewed
- [ ] #2 All tests pass (vendor/bin/pest)
- [ ] #3 Static analysis passes (vendor/bin/phpstan)
- [ ] #4 Pint passes (vendor/bin/pint --dirty)
- [ ] #5 No security regressions
<!-- AC:END -->
