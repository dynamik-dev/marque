---
id: task-2.11
title: 'Wave 3 Coordinator: DX polish'
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 20:33'
labels:
  - coordinator
  - parallel-group-3
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Developer experience improvements. 4 tickets running in parallel.

Context for this wave's tickets:
- README primary code examples use non-existent methods (allow, deny, save) and missing required params
- canDo()/cannotDo() are @deprecated but middleware, Blade directives, and Gate integration depend on them
- roles() returns Collection but looks like an Eloquent relationship; Scopeable trait gives cryptic error on missing $scopeType
- Cache invalidation calls $store->clear() which nukes the entire cache store, not just policy-engine keys
- Input contract: Contracts now have proper methods (Wave 2), hasRole() exists on trait
- Output contract: README works, API naming is clear, cache is safe to share

Tickets in this wave:
1. Fix README examples to match actual API
2. Remove canDo() deprecation, document as engine method
3. Rename roles() to getRoles(), add Scopeable runtime check
4. Scope cache invalidation to policy-engine keys only
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 All wave 3 tickets completed and reviewed
<!-- AC:END -->
