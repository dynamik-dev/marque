---
id: task-2.2
title: Invert CanDoMiddleware to fail-closed when HasPermissions trait is missing
status: Done
assignee: []
created_date: '2026-04-07 18:31'
updated_date: '2026-04-07 23:24'
labels:
  - parallel-group-1
  - security
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
CanDoMiddleware silently allows all requests if the User model lacks the HasPermissions trait. The method_exists check on cannotDo returns false, skipping the abort(403). This is a fail-open authorization bypass with zero warning. Wave briefing: task-2.1.

Spec: When method_exists($user, 'canDo') returns false, the middleware must abort(403) rather than passing through. This ensures any route protected by can_do middleware is fail-closed regardless of trait presence.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Middleware aborts 403 when user model lacks HasPermissions trait
- [x] #2 Test: authenticated user without trait gets 403 on can_do route
- [x] #3 Test: authenticated user with trait still works as before
- [ ] #4 Code review: approved by peer agent
<!-- AC:END -->
