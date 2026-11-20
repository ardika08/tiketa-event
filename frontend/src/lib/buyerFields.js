export function ticketScope(field) {
  return Array.isArray(field.ticket_type_ids)
    ? field.ticket_type_ids.map(Number)
    : field.ticket_type_id == null ? null : [Number(field.ticket_type_id)]
}

export function duplicateBuyerField(fields) {
  const seen = new Set()
  for (const { label, ticketKeys, inputKey } of fields) {
    const name = label.trim().toLowerCase() || 'field baru'
    for (const ticket of ticketKeys ?? [null]) {
      const keys = [JSON.stringify([ticket, name])]
      if (ticket === null && inputKey) keys.push(JSON.stringify(['global-input', inputKey]))
      if (keys.some((key) => seen.has(key))) return label.trim() || 'Field Baru'
      for (const key of keys) seen.add(key)
    }
  }
  return null
}

export function restoreScopedAnswers(answers, oldFields, currentFields) {
  const restored = { ...answers }
  const oldScopes = new Map(oldFields.map((field) => [field.id, ticketScope(field)]))
  for (const { field, ticketId, inputKey } of currentFields) {
    const oldScope = oldScopes.get(field.id)
    const oldKey = `f_${field.id}`
    if (ticketId !== null && oldScope?.length === 1 && oldScope[0] === ticketId &&
        restored[inputKey] === undefined && Object.hasOwn(answers, oldKey)) {
      restored[inputKey] = answers[oldKey]
    }
  }
  return restored
}

export function checkoutFields(fields, ticketIds) {
  const cart = new Set([...ticketIds].map(Number))
  return fields.flatMap((field) => {
    const scope = ticketScope(field)
    if (scope === null) {
      return [{ field, ticketId: null, inputKey: field.key || field.label.toLowerCase() }]
    }
    return scope.filter((id) => cart.has(id)).map((ticketId) => ({
      field,
      ticketId,
      inputKey: `f_${field.id}_${ticketId}`,
    }))
  })
}
