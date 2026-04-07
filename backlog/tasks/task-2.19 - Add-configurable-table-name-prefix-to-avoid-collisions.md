---
id: task-2.19
title: Add configurable table name prefix to avoid collisions
status: Done
assignee: []
created_date: '2026-04-07 18:33'
updated_date: '2026-04-07 22:43'
labels:
  - parallel-group-4
  - dx
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Table names (permissions, roles, assignments, boundaries, role_permissions) are highly generic and will collide with Spatie/Bouncer or custom tables. No prefix option exists. Wave briefing: task-2.16.

Spec: Add a 'table_prefix' config option (default empty string for backwards compat). Models must read the prefix via config and prepend it in getTable(). Migrations must use the config value. Document the collision risk in installation docs and suggest 'pe_' prefix for new installs alongside existing packages.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Config option table_prefix added with default empty string
- [x] #2 All 5 models use config prefix in getTable()
- [x] #3 Migrations use config prefix for table names
- [x] #4 Foreign key references use prefixed table names
- [x] #5 Tests pass with both empty and non-empty prefix
- [ ] #6 Code review: approved by peer agent
<!-- AC:END -->
