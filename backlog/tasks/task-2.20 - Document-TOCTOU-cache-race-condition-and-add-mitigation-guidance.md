---
id: task-2.20
title: Document TOCTOU cache race condition and add mitigation guidance
status: Done
assignee: []
created_date: '2026-04-07 18:34'
updated_date: '2026-04-07 22:43'
labels:
  - parallel-group-4
  - docs
dependencies: []
parent_task_id: task-2
priority: low
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Race condition: Request A starts evaluating (cache miss), Request B revokes a role and clears cache, Request A finishes with stale data and writes it to cache. Stale 'allowed' result persists for full TTL. This is inherent to cache-aside patterns. Wave briefing: task-2.16.

Spec: Document this as a known limitation in the caching docs. Add guidance: use shorter TTL for security-critical apps, use a dedicated cache store, consider cache tags with version counters for advanced mitigation. Add an inline code comment in CachedEvaluator explaining the race window.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Caching docs include TOCTOU race explanation and mitigation strategies
- [x] #2 CachedEvaluator has inline comment explaining the race window
- [x] #3 Config file comment recommends shorter TTL for security-critical apps
- [ ] #4 Code review: approved by peer agent
<!-- AC:END -->
