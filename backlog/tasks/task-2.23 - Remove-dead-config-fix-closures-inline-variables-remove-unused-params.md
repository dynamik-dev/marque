---
id: task-2.23
title: 'Remove dead config, fix closures, inline variables, remove unused params'
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
Accumulated dead code and style violations. Wave briefing: task-2.21.

1. document_format config option (config/policy-engine.php:16) — never read by any code. Remove or wire to parser selection.
2. Gate::before closure (PolicyEngineServiceProvider.php:173) — not static, captures $this unnecessarily. Make static. Also add type hint to $user param.
3. $isReplace parameter unused in importBoundaries() and importAssignments() (DefaultDocumentImporter.php) — remove.
4. Single-use variables: $path = $pathArg in ImportCommand (line 28-29), $ids in DefaultDocumentExporter (lines 46-48). Inline.
5. ValidateCommand (line 32-33) reads files via file_get_contents without using validatePath() — should use PrimitivesManager::validatePath().
6. Redundant inline comments in DefaultEvaluator (lines 59, 183) that restate what the code does — remove.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 document_format removed from config or wired to parser binding
- [x] #2 Gate::before closure is static with typed $user param
- [x] #3 Unused $isReplace params removed from importBoundaries and importAssignments
- [x] #4 Single-use variables inlined
- [x] #5 ValidateCommand uses validatePath()
- [x] #6 Redundant comments removed
- [x] #7 All tests pass, Pint clean
- [ ] #8 Code review: approved by peer agent
<!-- AC:END -->
