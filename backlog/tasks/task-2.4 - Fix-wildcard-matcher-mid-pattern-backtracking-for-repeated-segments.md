---
id: task-2.4
title: Fix wildcard matcher mid-pattern backtracking for repeated segments
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 19:37'
labels:
  - parallel-group-1
  - security
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
WildcardMatcher::segmentsMatch() uses a greedy forward scan for mid-pattern wildcards that fails when the next literal after '*' appears multiple times. Pattern 'a.*.b.c' against 'a.b.b.c' fails because greedy scan matches 'b' too early and can't backtrack. Wave briefing: task-2.1.

Spec: The segmentsMatch algorithm (WildcardMatcher.php:80-117) must correctly handle patterns like 'resources.*.admin' matching 'resources.billing.admin' AND patterns where the post-wildcard literal appears multiple times in the required string. Implement proper backtracking or document the limitation.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Pattern 'a.*.b.c' correctly matches 'a.b.b.c'
- [ ] #2 Pattern 'a.*.c' matches 'a.b.c' and 'a.b.d.c'
- [ ] #3 Existing trailing wildcard tests still pass
- [ ] #4 Tests for mid-pattern wildcards with repeated segments
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
