---
id: task-2.21
title: 'Wave 5 Coordinator: Code quality cleanup'
status: Done
assignee: []
created_date: '2026-04-07 18:34'
updated_date: '2026-04-07 22:59'
labels:
  - coordinator
  - parallel-group-5
dependencies: []
parent_task_id: task-2
priority: low
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Extract shared utilities and clean up dead code. 2 tickets running in parallel.

Context for this wave's tickets:
- parseSubject() logic duplicated in ExplainCommand, ListAssignmentsCommand, and DefaultDocumentImporter with inconsistent error handling
- Cache store resolution (config read + store selection) duplicated in CachedEvaluator, InvalidatePermissionCache, CacheClearCommand
- whereRaw uses ANSI double-quoted column name that breaks on MySQL without ANSI_QUOTES
- Dead code: document_format config option never read, unused $isReplace params, non-static Gate closure, single-use variables
- CLAUDE.md: Inline single-use variables, no compact(), PHP 8.4+ types

Tickets in this wave:
1. Extract SubjectParser utility, cache store resolver, fix whereRaw
2. Remove dead config, fix closures, inline variables, remove unused params
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All wave 5 tickets completed and reviewed
<!-- AC:END -->
