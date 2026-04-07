---
id: task-2.17
title: Align RoleMiddleware to include global assignments in scoped checks
status: Done
assignee: []
created_date: '2026-04-07 18:33'
updated_date: '2026-04-07 22:43'
labels:
  - parallel-group-4
  - behavior
dependencies:
  - task-2.7
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
RoleMiddleware (lines 38-49) calls forSubjectInScope() when a scope is provided, returning ONLY scoped assignments. DefaultEvaluator::gatherAssignments() merges both global and scoped assignments. A user with a global 'admin' role gets 403 from role:admin,team middleware even though can_do middleware would allow it. Wave briefing: task-2.16.

Spec: When scope is provided, RoleMiddleware should check both forSubjectInScope() and forSubjectGlobal() (now on the contract per Wave 2). If the user has the role in either global or scoped assignment, allow. This aligns with evaluator semantics. Update existing test that asserts the old behavior.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 RoleMiddleware checks global + scoped assignments when scope is provided
- [x] #2 User with global role passes role:rolename,scope middleware
- [x] #3 User without the role in either global or scoped context gets 403
- [x] #4 Existing middleware tests updated
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
