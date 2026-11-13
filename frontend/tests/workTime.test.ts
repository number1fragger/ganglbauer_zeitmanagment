/**
 * Zeitberechnung im Frontend – dieselben Faelle wie WorkingHoursTest im Backend.
 * Ausfuehren: npm run test:unit
 */
import { strict as assert } from 'node:assert'
import { test } from 'node:test'
import {
  blockedRanges,
  dayBounds,
  durationMinutes,
  endFor,
  timeWarnings,
  windowsOn,
  type WorkingHoursConfig,
} from '../src/utils/workTime.ts'

const config: WorkingHoursConfig = {
  timezone: 'Europe/Vienna',
  days: {
    '1': { start: '07:30', end: '17:00' },
    '2': { start: '07:30', end: '17:00' },
    '3': { start: '07:30', end: '17:00' },
    '4': { start: '07:30', end: '17:00' },
    '5': { start: '07:30', end: '14:00' },
    '6': null,
    '7': null,
  },
  breaks: [{ start: '12:00', end: '13:00', label: 'Mittagspause' }],
  summary: '',
}

/** Lokale Zeit "2026-09-28 10:00" (Montag) */
const d = (value: string) => {
  const [date, time = '00:00'] = value.split(' ')
  const [y, m, day] = date!.split('-').map(Number)
  const [h, min] = time.split(':').map(Number)
  return new Date(y!, m! - 1, day!, h!, min!)
}
const fmt = (date: Date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`

test('Arbeitsfenster ohne Mittagspause, Freitag kürzer, Wochenende frei', () => {
  const hhmm = (w: [Date, Date]) => [fmt(w[0]).slice(11), fmt(w[1]).slice(11)]
  assert.deepEqual(windowsOn(config, d('2026-09-28')).map(hhmm), [['07:30', '12:00'], ['13:00', '17:00']])
  assert.deepEqual(windowsOn(config, d('2026-10-02')).map(hhmm), [['07:30', '12:00'], ['13:00', '14:00']])
  assert.deepEqual(windowsOn(config, d('2026-10-03')), [])
})

test('Dauer ändern berechnet das Ende – Mittagspause wird übersprungen', () => {
  assert.equal(fmt(endFor(config, d('2026-09-28 10:00'), 180)), '2026-09-28 14:00')
  assert.equal(fmt(endFor(config, d('2026-09-28 10:00'), 120)), '2026-09-28 12:00')
})

test('mehrtägige Dauer läuft am nächsten Arbeitstag weiter', () => {
  assert.equal(fmt(endFor(config, d('2026-09-28 15:00'), 240)), '2026-09-29 09:30')
  assert.equal(fmt(endFor(config, d('2026-10-02 13:00'), 180)), '2026-10-05 09:30')
})

test('Beginn außerhalb der Arbeitszeit rechnet nach Uhrzeit', () => {
  assert.equal(fmt(endFor(config, d('2026-10-03 09:00'), 120)), '2026-10-03 11:00')
  assert.equal(durationMinutes(config, d('2026-10-03 09:00'), d('2026-10-03 11:00')), 120)
})

test('Beginn/Ende ändern aktualisiert die Dauer', () => {
  assert.equal(durationMinutes(config, d('2026-09-28 11:00'), d('2026-09-28 14:00')), 120)
  assert.equal(durationMinutes(config, d('2026-10-02 13:00'), d('2026-10-05 09:30')), 180)
})

test('Dauer und Ende sind widerspruchsfrei', () => {
  for (const start of ['2026-09-28 07:30', '2026-09-28 11:15', '2026-09-29 16:45', '2026-10-02 13:30']) {
    for (const minutes of [15, 60, 210, 480, 1000]) {
      const end = endFor(config, d(start), minutes)
      assert.equal(durationMinutes(config, d(start), end), minutes, `${start} + ${minutes}`)
    }
  }
})

test('Warnungen für Mittagspause und außerhalb der Arbeitszeit', () => {
  assert.deepEqual(timeWarnings(config, d('2026-09-28 11:00'), d('2026-09-28 14:00')), [])
  assert.match(timeWarnings(config, d('2026-09-28 12:15'), d('2026-09-28 13:30'))[0]!, /Beginn in der Mittagspause/)
  assert.match(timeWarnings(config, d('2026-09-28 11:00'), d('2026-09-28 13:00'))[0]!, /Ende in der Mittagspause/)
  assert.match(timeWarnings(config, d('2026-10-02 13:00'), d('2026-10-02 15:00'))[0]!, /Freitag: 07:30–14:00/)
  assert.match(timeWarnings(config, d('2026-10-03 08:00'), d('2026-10-03 09:00'))[0]!, /arbeitsfreien Tag \(Samstag\)/)
})

test('Kalenderraster und schattierte Zeiten', () => {
  assert.deepEqual(dayBounds(config), { start: 7.5, end: 17 })
  assert.deepEqual(blockedRanges(config, d('2026-10-02'), 7, 17), [
    { from: 7, to: 7.5, label: '' },
    { from: 12, to: 13, label: 'Mittagspause' },
    { from: 14, to: 17, label: 'Feierabend' },
  ])
})
