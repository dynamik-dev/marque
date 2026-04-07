---
id: task-2.1
title: 'Wave 1 Coordinator: Security hardening'
status: Done
assignee: []
created_date: '2026-04-07 18:31'
updated_date: '2026-04-07 19:37'
labels:
  - coordinator
  - parallel-group-1
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Security-critical fixes that must land first. 4 tickets running in parallel.

Context for this wave's tickets:
- CanDoMiddleware silently allows requests when HasPermissions trait is missing (fail-open authorization bypass)
- Permission/role ID strings accept arbitrary input including colons (breaks scope parsing) and ! prefix (creates deny rules)
- WildcardMatcher's greedy scan fails with mid-pattern wildcards when next-literal appears multiple times
- Document import accepts arbitrary morph types enabling cross-tenant privilege escalation
- CLAUDE.md constraints: Pest tests required, test through contracts, PHP 8.4+ types

Tickets in this wave:
1. Invert CanDoMiddleware to fail-closed — 3-line fix + test
2. Add permission/role string format validation — regex on register()/save()
3. Fix wildcard matcher mid-pattern backtracking — algorithm fix + tests
4. Validate morph types during document import — whitelist check
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 All wave 1 tickets completed and reviewed
<!-- AC:END -->
