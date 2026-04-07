---
id: task-2.3
title: Add format validation for permission and role ID strings
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 19:31'
labels:
  - parallel-group-1
  - security
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Permission and role ID strings accept arbitrary input. Strings containing colons are misinterpreted by parseScope() (e.g., 'admin:panel' parses as permission='admin', scope='panel'). Strings starting with '!' create deny rules. Empty strings are accepted. Wave briefing: task-2.1.

Spec: Add validation in EloquentPermissionStore::register() and EloquentRoleStore::save() rejecting: empty strings, strings containing ':' (scope separator), strings starting with '!' (deny prefix), and strings containing whitespace. Throw InvalidArgumentException with a descriptive message.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 register() throws InvalidArgumentException for empty, colon-containing, !-prefixed, and whitespace-containing permission strings
- [ ] #2 save() throws InvalidArgumentException for invalid role IDs
- [ ] #3 Error messages include the invalid string and expected format
- [ ] #4 Tests for each rejection case and for valid strings still passing
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
