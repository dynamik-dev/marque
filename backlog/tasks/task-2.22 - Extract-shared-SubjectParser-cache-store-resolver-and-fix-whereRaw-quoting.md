---
id: task-2.22
title: 'Extract shared SubjectParser, cache store resolver, and fix whereRaw quoting'
status: Done
assignee: []
created_date: '2026-04-07 18:34'
updated_date: '2026-04-07 22:59'
labels:
  - parallel-group-5
  - quality
dependencies: []
parent_task_id: task-2
priority: low
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Three duplicated patterns need extraction. Wave briefing: task-2.21.

1. parseSubject() in ExplainCommand (lines 60-73), ListAssignmentsCommand (lines 59-72), DefaultDocumentImporter (lines 211-223) — each handles malformed input differently. Extract to Support\SubjectParser::parse(string $subject): array with consistent validation (throw on missing '::').

2. Cache store resolution in CachedEvaluator (lines 63-69), InvalidatePermissionCache (lines 37-43), CacheClearCommand (lines 19-21) — same config read + store() pattern. Extract to a static helper or shared trait.

3. EloquentPermissionStore::all() (line 66) uses whereRaw with ANSI double-quoted column name ("id") which breaks on MySQL. Replace with Laravel query builder ->where('id', 'like', ...).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 SubjectParser utility exists with consistent validation
- [x] #2 All 3 parseSubject callsites use SubjectParser
- [x] #3 Cache store resolution extracted — all 3 callsites use shared method
- [x] #4 whereRaw replaced with query builder ->where() in EloquentPermissionStore
- [x] #5 All existing tests pass
- [ ] #6 Code review: approved by peer agent
<!-- AC:END -->
