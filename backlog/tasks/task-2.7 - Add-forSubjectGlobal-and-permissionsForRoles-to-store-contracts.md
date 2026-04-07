---
id: task-2.7
title: Add forSubjectGlobal and permissionsForRoles to store contracts
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 20:00'
labels:
  - parallel-group-2
  - architecture
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
forSubjectGlobal(), forSubjectGlobalAndScope() are called via method_exists on AssignmentStore in DefaultEvaluator (lines 266, 268, 278, 280), PolicyEngineServiceProvider (lines 158, 160), and RoleMiddleware (lines 44, 46). permissionsForRoles() is called via method_exists on RoleStore in DefaultEvaluator (lines 320, 322). This defeats static analysis and violates dependency inversion. Wave briefing: task-2.6.

Spec: Add forSubjectGlobal(string $subjectType, string|int $subjectId): Collection, forSubjectGlobalAndScope(string $subjectType, string|int $subjectId, string $scope): Collection to AssignmentStore contract. Add permissionsForRoles(array $roleIds): Collection to RoleStore contract. Remove ALL method_exists/call_user_func calls. Existing Eloquent implementations already have these methods.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 forSubjectGlobal and forSubjectGlobalAndScope declared on AssignmentStore contract
- [ ] #2 permissionsForRoles declared on RoleStore contract
- [ ] #3 Zero method_exists or call_user_func calls remain in src/
- [ ] #4 All existing tests pass without modification
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
