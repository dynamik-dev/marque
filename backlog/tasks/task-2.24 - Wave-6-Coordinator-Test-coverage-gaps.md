---
id: task-2.24
title: 'Wave 6 Coordinator: Test coverage gaps'
status: Done
assignee: []
created_date: '2026-04-07 18:34'
updated_date: '2026-04-07 23:17'
labels:
  - coordinator
  - parallel-group-6
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Fill critical test coverage gaps identified in the review. 2 tickets running in parallel.

Context for this wave's tickets:
- No test for wildcard deny across multiple roles in scoped context (security-critical)
- No test for middleware bypass when HasPermissions trait is missing (covered by Wave 1 fix, but needs regression test)
- No test for document import with forged morph types (covered by Wave 1 fix, needs regression test)
- No test for boundary enforcement on global assignments checking scoped permissions
- No test for empty permission strings, special characters, colons in permission names, mid-pattern wildcards with repeated segments
- No test for empty boundary max_permissions array
- CachedEvaluator cache-hit test manually sets cache instead of testing real behavior
- effectivePermissions test uses toContain() instead of exact verification
- Input contract: All code fixes from Waves 1-5 landed
- Output contract: Comprehensive test coverage for security-critical paths and edge cases

Tickets in this wave:
1. Security test gaps — wildcard deny, boundary on global assignments, Sanctum+middleware
2. Edge case tests — empty strings, special chars, mid-pattern wildcards, empty boundaries, cache assertions
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All wave 6 tickets completed and reviewed
<!-- AC:END -->
