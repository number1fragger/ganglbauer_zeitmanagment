/**
 * Konflikte im Kalender: Ueberschneidungen zweier Auftraege zaehlen nur, wenn
 * parallele Auftraege ausgeschaltet sind; Abwesenheiten zaehlen immer.
 */
import { strict as assert } from 'node:assert'
import { test } from 'node:test'
import type { Absence, Job, UserRef } from '../src/api/types.ts'
import { findConflicts, listConflicts } from '../src/utils/domain.ts'

const anton: UserRef = { id: 1, fullName: 'Anton Huber', shortName: 'A. Huber', initials: 'AH' }

const job = (id: number, startsAt: string, endsAt: string): Job =>
  ({ id, title: `Auftrag ${id}`, status: 'offen', startsAt, endsAt, assignee: anton, apprentice: null }) as Job

const overlapping = [
  job(1, '2026-09-28T09:00:00+02:00', '2026-09-28T11:00:00+02:00'),
  job(2, '2026-09-28T10:00:00+02:00', '2026-09-28T11:00:00+02:00'),
]

const vacation = {
  id: 7,
  user: anton,
  label: 'Urlaub',
  startsAt: '2026-09-28T00:00:00+02:00',
  endsAt: '2026-09-29T00:00:00+02:00',
} as Absence

test('Parallele Aufträge erlaubt: gleichzeitige Aufträge sind kein Konflikt', () => {
  assert.deepEqual(listConflicts(overlapping, [], { overlaps: false }), [])
  assert.equal(findConflicts(overlapping, [], { overlaps: false }).size, 0)
})

test('Parallele Aufträge aus: die Überschneidung ist ein Konflikt', () => {
  const conflicts = listConflicts(overlapping, [], { overlaps: true })
  assert.equal(conflicts.length, 1)
  assert.equal(conflicts[0]?.kind, 'overlap')
  assert.deepEqual([...findConflicts(overlapping, [], { overlaps: true }).keys()], [1, 2])
})

test('Abwesenheiten bleiben immer ein Konflikt', () => {
  const conflicts = listConflicts(overlapping, [vacation], { overlaps: false })
  assert.deepEqual(
    conflicts.map((c) => c.kind),
    ['absence', 'absence'],
  )
})
