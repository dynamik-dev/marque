---
id: task-2.26
title: >-
  Add edge case tests: empty strings, special chars, mid-pattern wildcards,
  cache assertions
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
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Edge cases and assertion quality issues identified in test review. Wave briefing: task-2.24.

Missing tests:
1. Empty permission string '' passed to matches(), can(), canDo() — verify defined behavior.
2. Permission strings with special characters (spaces, unicode, very long strings).
3. Multiple wildcards in single string: *.*.own pattern.
4. Empty boundary max_permissions [] — should deny everything in scope.
5. Concurrent cache operations (assign + update role in same request).
6. @cannotDo with unauthenticated user returns false (document or fix).

Assertion fixes:
7. CachedEvaluatorTest cache-hit test (lines 64-81) manually sets cache — should verify second call hits cache without manual put().
8. effectivePermissions test (lines 234-243) uses toContain() — use toEqualCanonicalizing() for exact verification.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Tests for empty string, special char, and long permission strings
- [x] #2 Test for *.*.own multi-wildcard pattern
- [x] #3 Test for empty boundary max_permissions denying everything
- [x] #4 CachedEvaluator cache-hit test verifies real cache behavior without manual put()
- [x] #5 effectivePermissions assertions use exact verification
- [ ] #6 Code review: approved by peer agent
<!-- AC:END -->
