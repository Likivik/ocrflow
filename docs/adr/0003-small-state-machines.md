# 0003 — Several small state machines, not one app-wide status

**Status:** accepted.
**Context date:** 2026-11-27, OCRFlow 0.1.68, Nextcloud 34, PostgreSQL.

## The problem

A single `status` column cannot describe a run, a file version, a stashed
artifact and a spend booking at once. One status forces unrelated lifecycles
into one order: a file with no run, two runs for different versions, or a
stashed result waiting for a human all become special cases. Every writer then
needs to know every other writer's transitions.

## Decision

Keep four small machines, each owning one question:

- **Run** — `queued → claimed → settled | error`. Claim token and revision fence
  every transition.
- **File** — an observation is durable until a run owns the file; supersession
  removes only queued work. No status field.
- **Stash artifact** — `pending → applied | discarded`, closed under one
  exclusive decision lock.
- **Spend** — reserved → booked or released, tied to the run that caused it.

Reconcile is the only cross-machine reader. It drains observations, recovers
runs with exact ownership fences, settles doomed runs, and releases orphaned
bookings and locks.

## Why not one machine

- A file needs no row until work exists; a run needs no knowledge of stashes.
- Supersession is legal only before a claim. In one machine that rule is a set
  of forbidden edges; here it is one predicate.
- A crash leaves each machine in a state the sweep can reason about without
  reconstructing the others.
- The machines can be tested independently against real PostgreSQL; a combined
  machine needs a cross-product of fixtures.

## Consequences

- Transitions are narrower and each has a named owner.
- Cross-machine effects are deliberately eventual through Reconcile, except
  Apply/Discard, which serialise on the artifact lock.
- Adding a new concern means adding a machine with its own transitions, not
  widening a shared enum.
- The cost is more tables and more explicit keys; the benefit is that no
  transition needs to know why another machine is in its current state.
