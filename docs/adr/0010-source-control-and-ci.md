# 0010. GitHub Free, pull-request workflow, lean CI

- Status: Accepted
- Date: 2026-10-02
- Deciders: Mayura Consultancy Services (tech lead), Sekal

## Context

The project starts on GitHub Free (private repositories) and moves to GitHub Team when both squads are active. Free has 2,000 Actions minutes per month and no branch protection on private repositories.

## Decision

All changes go through pull requests and only the tech lead merges. Workflows run on relevant path changes only, cancel superseded runs, cache dependencies and time out. iOS builds for the mobile apps will run on Codemagic; Android and tests on GitHub Actions.

## Consequences

CI stays inside the free allowance during early sprints. Branch protection is switched on when moving to the Team plan.
