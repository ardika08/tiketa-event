import test from 'node:test'
import assert from 'node:assert/strict'
import { ticketScope, checkoutFields, duplicateBuyerField, restoreScopedAnswers } from '../src/lib/buyerFields.js'

test('legacy draft answers stay with their original ticket when the field scope is expanded', () => {
  const previous = [{ id: 7, label: 'Nama Anak', ticket_type_id: 1 }]
  const current = checkoutFields([{ id: 7, label: 'Nama Anak', ticket_type_ids: [1, 2] }], [1, 2])
  assert.deepEqual(restoreScopedAnswers({ f_7: 'Ani' }, previous, current), { f_7: 'Ani', f_7_1: 'Ani' })
})

test('only identical labels in the same answer bucket collide', () => {
  const a = { label: 'Usia', ticketKeys: ['1', '2'] }
  const b = { label: ' usia ', ticketKeys: ['2', '3'] }
  assert.equal(duplicateBuyerField([a, b]), 'usia')
  assert.equal(duplicateBuyerField([a, { ...b, ticketKeys: ['3'] }]), null)
  assert.equal(duplicateBuyerField([a, { ...b, ticketKeys: null }]), null)
  assert.equal(duplicateBuyerField([{ ...a, ticketKeys: null }, { ...b, ticketKeys: null }]), 'usia')
})

const child = { id: 7, label: 'Nama Anak', wajib: true, ticket_type_ids: [1, 2] }
const visitorAge = { id: 8, label: 'Usia', ticket_type_id: 3 }
const contact = { id: 9, label: 'Instagram', key: 'instagram' }

test('multi-ticket fields create distinct answers only for selected ticket types in the cart', () => {
  const fields = checkoutFields([child, visitorAge, contact], [1, 2, 2, 3, 4])
  assert.deepEqual(fields.map(({ ticketId, inputKey }) => [ticketId, inputKey]), [
    [1, 'f_7_1'], [2, 'f_7_2'], [3, 'f_8_3'], [null, 'instagram'],
  ])
})

test('visitor-only cart never gets a question scoped to child tickets', () => {
  assert.deepEqual(checkoutFields([child, visitorAge], [3, 4]).map(({ field }) => field.label), ['Usia'])
})

test('legacy ticket scope and global fields still resolve', () => {
  assert.deepEqual(ticketScope(visitorAge), [3])
  assert.equal(ticketScope(contact), null)
  assert.deepEqual(ticketScope({ ticket_type_ids: ['2'], ticket_type_id: 3 }), [2])
})
