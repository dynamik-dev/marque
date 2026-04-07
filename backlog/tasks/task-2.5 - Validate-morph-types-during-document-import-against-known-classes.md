---
id: task-2.5
title: Validate morph types during document import against known classes
status: Done
assignee: []
created_date: '2026-04-07 18:32'
updated_date: '2026-04-07 19:37'
labels:
  - parallel-group-1
  - security
dependencies: []
parent_task_id: task-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
DefaultDocumentImporter::parseSubject() splits subject strings like 'App\\Models\\User::1' with no validation. A crafted policy document can inject arbitrary morph types, enabling cross-tenant privilege escalation if tenant admins can import documents. Wave briefing: task-2.1.

Spec: Validate subject_type in importAssignments() against Laravel's morph map or a configurable whitelist. Also fix parseSubject() to throw on malformed input (missing '::' separator currently returns empty subject_id).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Import rejects subjects with morph types not in Laravel morph map or whitelist
- [ ] #2 parseSubject() throws on malformed input (no '::' separator)
- [ ] #3 Test: import with forged morph type is rejected
- [ ] #4 Test: import with valid morph type succeeds
- [ ] #5 Code review: approved by peer agent
<!-- AC:END -->
