---
id: task-2.18
title: >-
  Document boundary bypass for unscoped checks, add enforce_boundaries_on_global
  config
status: Done
assignee: []
created_date: '2026-04-07 18:33'
updated_date: '2026-04-07 22:43'
labels:
  - parallel-group-4
  - behavior
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
passesBoundaryCheck() (DefaultEvaluator.php:290-303) returns true when scope is null, so users with global *. * assignments bypass all boundaries. The evaluation order docs say 'Assignments -> Roles -> Permissions -> Boundary check -> Deny wins' which implies boundaries always apply. Wave briefing: task-2.16.

Spec: Add a prominent doc section explaining that global assignments are inherently unbounded. Add a config option 'enforce_boundaries_on_global' (default false for backwards compat) that, when true, applies boundary checks even for unscoped evaluations by checking all boundaries the subject might be affected by. Add inline config comment explaining the tradeoff.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Config option enforce_boundaries_on_global added with default false
- [x] #2 When enabled, unscoped checks apply boundary filtering
- [x] #3 Config file has inline comment explaining the behavior
- [x] #4 Documentation updated with boundary behavior explanation
- [x] #5 Tests for both config values
- [ ] #6 Code review: approved by peer agent
<!-- AC:END -->
