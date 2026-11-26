import test from 'node:test'
import assert from 'node:assert/strict'
import { appendBuyerFormColumns } from '../src/lib/buyerExport.js'

test('exports scoped answers without mixing two ticket types or filling missing answers', () => {
  const buyers = [
    { ticket_names: { 1: 'Visitor Day 1', 2: 'Visitor Day 2' }, form_export: [
      { label: 'HPL', ticket_id: null, value: '20 Desember' },
      { label: 'Usia', ticket_id: 1, value: '29' },
      { label: 'Usia', ticket_id: 2, value: '31' },
    ] },
    { ticket_names: { 1: 'Visitor Day 1' }, form_export: [{ label: 'Usia', ticket_id: 1, value: '34' }] },
    {},
  ]
  assert.deepEqual(appendBuyerFormColumns([['Kode'], ['A'], ['B'], ['C']], buyers), [
    ['Kode', 'HPL', 'Usia — Visitor Day 1', 'Usia — Visitor Day 2'],
    ['A', '20 Desember', '29', '31'],
    ['B', '', '34', ''],
    ['C', '', '', ''],
  ])
})

test('ticket headers keep their meaning when the row order is reversed', () => {
  const buyers = [
    { ticket_names: { 4: 'Workshop', 5: 'Workshop' }, form_export: [{ label: 'HPL', ticket_id: 4, value: 'A' }, { label: 'HPL', ticket_id: 5, value: 'B' }] },
    { ticket_names: { 4: 'Workshop', 5: 'Workshop' }, form_export: [{ label: 'HPL', ticket_id: 5, value: 'B' }, { label: 'HPL', ticket_id: 4, value: 'A' }] },
  ]
  const [headers, first, second] = appendBuyerFormColumns([['Kode'], ['1'], ['2']], buyers)
  assert.equal(new Set(headers).size, headers.length)
  assert.deepEqual(first.slice(1), second.slice(1))
  assert.deepEqual(headers.slice(1), ['HPL — Workshop', 'HPL — Workshop (2)'])
})
