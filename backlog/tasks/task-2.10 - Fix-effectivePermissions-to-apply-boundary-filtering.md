---
id: task-2.10
title: Fix effectivePermissions() to apply boundary filtering
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 20:05'
labels:
  - parallel-group-2
  - architecture
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
DefaultEvaluator::effectivePermissions() (lines 206-229) gathers permissions from roles but never checks boundaries. It returns permissions that can() would actually deny. This is likely a bug — a developer using effectivePermissions() to build a permissions UI would show permissions the user doesn't actually have. Wave briefing: task-2.6.

Spec: When a scope is provided, effectivePermissions() must filter the returned permissions through passesBoundaryCheck(). Only permissions that pass the boundary check should be included in the result. When scope is null, boundaries don't apply (consistent with can() behavior).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 effectivePermissions() filters results through boundary check when scope is provided
- [ ] #2 effectivePermissions() without scope returns unfiltered (consistent with can())
- [ ] #3 Test: scoped effectivePermissions excludes permissions blocked by boundary
- [ ] #4 Code review: approved by peer agent
<!-- AC:END -->
