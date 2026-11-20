export function ticketScope(field) {
  return Array.isArray(field.ticket_type_ids)
    ? field.ticket_type_ids.map(Number)
    : field.ticket_type_id == null ? null : [Number(field.ticket_type_id)]
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
      inputKey: scope.length === 1 ? `f_${field.id}` : `f_${field.id}_${ticketId}`,
    }))
  })
}
