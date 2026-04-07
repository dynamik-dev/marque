---
id: task-2.12
title: Fix README code examples to match actual API surface
status: Done
assignee: []
created_date: '2026-04-07 18:33'
updated_date: '2026-04-07 20:33'
labels:
  - parallel-group-3
  - dx
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
README (lines 27-29) shows Primitives::role('editor')->allow(...)->deny(...)->save() but RoleBuilder only has grant(), ungrant(), remove(). Primitives::role() requires both $id and $name params but README passes only one. grant() takes an array but README passes variadic strings. The first thing a developer reads is non-functional. Wave briefing: task-2.11.

Spec: Update README to use grant()/ungrant() with array syntax and both required params. Also consider making $name optional (defaulting to ucfirst of ID) for better prototyping DX.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 All README code examples are executable against the actual API
- [ ] #2 Primitives::role() call includes both required params or $name is made optional
- [ ] #3 grant() calls use array syntax
- [ ] #4 Code review: approved by peer agent
<!-- AC:END -->
