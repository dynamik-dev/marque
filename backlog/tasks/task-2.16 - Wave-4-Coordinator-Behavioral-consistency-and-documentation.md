---
id: task-2.16
title: 'Wave 4 Coordinator: Behavioral consistency and documentation'
status: Done
assignee: []
created_date: '2026-04-07 18:33'
updated_date: '2026-04-07 22:43'
labels:
  - coordinator
  - parallel-group-4
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Align behavioral inconsistencies and document edge cases. 4 tickets running in parallel.

Context for this wave's tickets:
- RoleMiddleware only checks forSubjectInScope() when scope is provided, excluding global assignments. DefaultEvaluator::gatherAssignments() includes both. User with global 'admin' gets 403 from role:admin,team middleware.
- Boundaries are skipped when scope is null (passesBoundaryCheck returns true). Users with global *. * bypass all boundaries. Evaluation order docs imply boundaries always apply.
- Table names (permissions, roles, assignments, boundaries) are highly generic and collide with Spatie/Bouncer/custom tables.
- TOCTOU race: stale evaluation result can be cached after invalidation in concurrent environments.
- Input contract: Contracts fixed (Wave 2), DX naming clean (Wave 3)
- Output contract: Consistent behavior across middleware/evaluator, documented edge cases, configurable tables

Tickets in this wave:
1. Align RoleMiddleware to include global assignments in scoped checks
2. Document boundary bypass for unscoped checks, add config toggle
3. Add table name prefix config option
4. Document TOCTOU cache race, add mitigation guidance
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All wave 4 tickets completed and reviewed
<!-- AC:END -->
