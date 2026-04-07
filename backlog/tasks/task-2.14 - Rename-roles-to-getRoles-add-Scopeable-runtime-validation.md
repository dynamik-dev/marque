---
id: task-2.14
title: 'Rename roles() to getRoles(), add Scopeable runtime validation'
status: Done
assignee: []
created_date: '2026-04-07 18:33'
updated_date: '2026-04-07 20:33'
labels:
  - parallel-group-3
  - dx
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
HasPermissions::roles() (line 107) returns a Collection but looks like an Eloquent relationship method. Developers expect $user->roles() to return a Relation and $user->roles to return via magic accessor. This prevents eager loading and causes N+1 confusion. Separately, Scopeable::toScope() (line 20) accesses $this->scopeType without validation — missing property gives 'Undefined property' instead of a helpful error. Wave briefing: task-2.11.

Spec: Rename roles() to getRoles() and rolesFor() to getRolesFor() on HasPermissions. In Scopeable::toScope(), add a runtime check that throws LogicException with a message like 'YourModel must define a protected string $scopeType property to use the Scopeable trait' when the property is missing.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 roles() renamed to getRoles(), rolesFor() renamed to getRolesFor()
- [ ] #2 Scopeable::toScope() throws LogicException with helpful message when $scopeType undefined
- [ ] #3 All existing tests updated to use new method names
- [ ] #4 Test: Scopeable without $scopeType throws LogicException
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
