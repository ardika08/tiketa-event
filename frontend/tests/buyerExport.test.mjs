import test from 'node:test'
import assert from 'node:assert/strict'
import { appendBuyerFormColumns } from '../src/lib/buyerExport.js'

test('one column per field name, answers from every ticket type merged', () => {
  const buyers = [
    { ticket_names: { 1: 'Visitor Day 1', 2: 'Visitor Day 2', 3: 'Workshop' }, form_export: [
      { label: 'HPL', ticket_id: null, value: '20 Desember' },
      { label: 'Usia', ticket_id: 1, value: '29' },
      { label: 'Usia', ticket_id: 2, value: '31' },
      { label: 'Nama Anak', ticket_id: 3, value: 'Aisyah' },
    ] },
    { ticket_names: { 1: 'Visitor Day 1' }, form_export: [{ label: 'Usia', ticket_id: 1, value: '34' }] },
    {},
  ]
  assert.deepEqual(appendBuyerFormColumns([['Kode'], ['A'], ['B'], ['C']], buyers), [
    ['Kode', 'HPL', 'Usia', 'Nama Anak'],
    ['A', '20 Desember', '29, 31', 'Aisyah'],
    ['B', '', '34', ''],
    ['C', '', '', ''],
  ])
})

test('same answer from several tickets is not duplicated and gaps are skipped', () => {
  const buyers = [{ form_export: [
    { label: 'Usia', ticket_id: 1, value: '29' },
    { label: 'Usia', ticket_id: 2, value: '29' },
    { label: 'Usia', ticket_id: 3, value: '31' },
    { label: 'Nama Anak', ticket_id: 2, value: '' },
  ] }]
  assert.deepEqual(appendBuyerFormColumns([['Kode'], ['A']], buyers), [
    ['Kode', 'Usia'],
    ['A', '29, 31'],
  ])
})

test('column names follow the form field and never duplicate an existing header', () => {
  const buyers = [{ form_export: [{ label: 'Instagram', ticket_id: null, value: '@baru' }, { label: 'HPL', ticket_id: 4, value: 'A' }] }]
  const [headers, row] = appendBuyerFormColumns([['Kode', 'Instagram'], ['X', '@profil']], buyers)
  assert.deepEqual(headers, ['Kode', 'Instagram', 'Instagram (2)', 'HPL'])
  assert.deepEqual(row, ['X', '@profil', '@baru', 'A'])
})
