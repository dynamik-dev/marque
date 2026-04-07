---
id: task-2.13
title: 'Remove @deprecated from canDo/cannotDo, document as internal engine methods'
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
canDo() and cannotDo() on HasPermissions (lines 26, 39) are marked @deprecated with guidance to use $user->can() via Gate. But middleware (CanDoMiddleware:34), Blade directives (@canDo, @cannotDo), and the Gate hook itself all depend on canDo(). A developer reading the deprecation tries to avoid calling it, but the entire package depends on it. Wave briefing: task-2.11.

Spec: Remove @deprecated annotations. Update docblocks to explain canDo()/cannotDo() are the engine methods powering Gate integration, middleware, and Blade directives. Document $user->can() as the recommended public API while clarifying canDo() is the internal evaluation entry point.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 @deprecated removed from canDo() and cannotDo()
- [ ] #2 Docblocks explain the relationship between can(), canDo(), Gate, and middleware
- [ ] #3 No functional code changes
- [ ] #4 Code review: approved by peer agent
<!-- AC:END -->
