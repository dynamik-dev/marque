---
id: task-2.8
title: 'Add hasRole() to HasPermissions trait, simplify @hasRole Blade directive'
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
The @hasRole Blade directive (PolicyEngineServiceProvider.php:132-168) contains 30+ lines of authorization logic including store queries, assignment filtering, and method_exists checks. The DX layer should delegate only. There is no hasRole() method on HasPermissions, so this logic can't be reused programmatically. Wave briefing: task-2.6.

Spec: Add hasRole(string $role, mixed $scope = null): bool to the HasPermissions trait. Move the authorization logic from the Blade directive into this method. Simplify the @hasRole directive to a one-liner that delegates to $user->hasRole(). This also gives developers a programmatic API for role checks.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 HasPermissions trait has hasRole(string $role, mixed $scope = null): bool
- [ ] #2 @hasRole Blade directive is a one-liner delegating to hasRole()
- [ ] #3 Existing @hasRole Blade tests still pass
- [ ] #4 New test: programmatic hasRole() call works with and without scope
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
