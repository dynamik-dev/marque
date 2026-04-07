---
id: task-2.25
title: >-
  Add security-critical test coverage: wildcard deny, boundary on global,
  Sanctum middleware
status: Done
assignee: []
created_date: '2026-04-07 18:34'
updated_date: '2026-04-07 23:17'
labels:
  - parallel-group-6
  - testing
dependencies:
  - task-2.1
  - task-2.6
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Security-critical evaluation paths are undertested. Wave briefing: task-2.24.

Missing tests:
1. Wildcard deny across roles in scoped context: User has role-A with ['billing.*'] and role-B with ['!billing.refund'] in scope org::1. Verify billing.refund denied, billing.view allowed.
2. Multiple deny rules with wildcards: Role-A grants '*.*', role-B has ['!posts.*']. Verify posts.create denied, billing.manage allowed.
3. Boundary enforcement on global assignments: User has global *. * assignment, boundary on org::acme limits to posts.*, check billing.manage:org::acme is denied.
4. deny_unbounded_scopes via can() path (currently only tested via explain()).
5. Sanctum token scoping through middleware (currently only tested at evaluator level).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Test: wildcard deny !billing.* from role-B denies billing.refund granted by role-A in scoped context
- [x] #2 Test: *. * grant with !posts.* deny correctly denies posts.* but allows billing.*
- [x] #3 Test: global *. * assignment respects boundary when checking scoped permission
- [x] #4 Test: deny_unbounded_scopes works via can() not just explain()
- [x] #5 Test: Sanctum token scoping denies at middleware level
- [ ] #6 Code review: approved by peer agent
<!-- AC:END -->
