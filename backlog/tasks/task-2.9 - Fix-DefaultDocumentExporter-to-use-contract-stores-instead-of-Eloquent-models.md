---
id: task-2.9
title: Fix DefaultDocumentExporter to use contract stores instead of Eloquent models
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 20:00'
labels:
  - parallel-group-2
  - architecture
dependencies: []
parent_task_id: task-2
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
DefaultDocumentExporter accepts AssignmentStore in constructor but never stores it (missing private readonly prefix). exportAssignments() queries Assignment::query() directly (lines 57-64) and exportBoundaries() queries Boundary::query()->get() directly (line 135). This bypasses the contract layer, coupling the exporter to Eloquent. Wave briefing: task-2.6.

Spec: Add private readonly to the AssignmentStore constructor parameter. Replace Assignment::query() and Boundary::query() calls with contract store method calls. This ensures custom store implementations are visible to the export workflow.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 DefaultDocumentExporter stores and uses AssignmentStore via private readonly
- [ ] #2 No direct Assignment::query() or Boundary::query() calls in the exporter
- [ ] #3 Export tests still pass
- [ ] #4 Code review: approved by peer agent
<!-- AC:END -->
