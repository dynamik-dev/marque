---
id: task-1.4
title: Convert non-static closures to static
status: Done
assignee: []
created_date: '2026-03-06 22:33'
updated_date: '2026-03-06 22:37'
labels:
  - parallel-group-2
  - style
dependencies: []
parent_task_id: task-1
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
SLOP-025: Add static keyword to ~10+ closures that don't reference $this. Affects DefaultEvaluator.php (lines 221, 260, 264), HasPermissions.php (lines 122, 139), ListPermissionsCommand.php (line 28), ListAssignmentsCommand.php (lines 85, 87), DefaultDocumentExporter.php (lines 77, 108, 132).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 All non-static closures that don't bind $this converted to static fn/static function across listed files
- [x] #2 No closure binds $this unnecessarily
- [x] #3 Tests passing
- [x] #4 Code review: approved by peer agent
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Converted 8 closures to static across 5 files. Skipped 2 closures that reference $this (DefaultEvaluator::effectivePermissions, DefaultDocumentExporter::exportRoles).
<!-- SECTION:NOTES:END -->
