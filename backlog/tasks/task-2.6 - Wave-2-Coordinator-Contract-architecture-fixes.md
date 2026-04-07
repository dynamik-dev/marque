---
id: task-2.6
title: 'Wave 2 Coordinator: Contract architecture fixes'
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 20:00'
labels:
  - coordinator
  - parallel-group-2
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Restructure interfaces and fix contract violations. 4 tickets running in parallel.

Context for this wave's tickets:
- forSubjectGlobal(), forSubjectGlobalAndScope(), and permissionsForRoles() are called via method_exists/call_user_func in 6 locations because the contracts don't declare them
- @hasRole Blade directive contains 30+ lines of authorization logic that should be in the trait
- DefaultDocumentExporter accepts AssignmentStore in constructor but never stores it, queries Eloquent models directly
- effectivePermissions() gathers permissions from roles but never applies boundary filtering, returning permissions that can() would deny
- Input contract: Wave 1 security fixes landed
- Output contract: Clean contract interfaces, DX layer delegates only, exporter uses contracts
- CLAUDE.md: DX layer contains NO logic — delegates to contracts. All implementations coded to interfaces.

Tickets in this wave:
1. Add optimization methods to store contracts, remove method_exists
2. Add hasRole() to HasPermissions trait, simplify @hasRole Blade directive
3. Fix DefaultDocumentExporter to use contract stores, not Eloquent models
4. Fix effectivePermissions() to apply boundary filtering
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 All wave 2 tickets completed and reviewed
<!-- AC:END -->
