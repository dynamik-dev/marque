---
id: task-2.15
title: Scope cache invalidation to policy-engine keys only
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
InvalidatePermissionCache listener (line 33) and CacheClearCommand (line 21) call $store->clear() which flushes the ENTIRE cache store. If the app shares the default store with sessions, rate limiting, or general cache, every permission change wipes all cached data. Wave briefing: task-2.11.

Spec: Replace $store->clear() with tagged caching ($store->tags('policy-engine')->flush()) where the driver supports it, or iterate/delete only keys with the 'policy-engine:' prefix. CachedEvaluator must write to the same tag/prefix. CacheClearCommand should prompt for confirmation when not using --force.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Cache writes use tags or prefixed keys
- [ ] #2 Invalidation only clears policy-engine keys, not the entire store
- [ ] #3 CacheClearCommand prompts for confirmation without --force
- [ ] #4 Tests verify non-policy-engine cache keys survive invalidation
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
