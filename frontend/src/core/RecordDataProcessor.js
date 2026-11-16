export class RecordDataProcessor {
  constructor(registry) {
    this.registry = registry;
    this.rows = [];
  }
  replace(rows) {
    this.rows = rows;
  }
  all(type) {
    return this.rows.filter((r) => r.recordTypeCode === type);
  }
  get(type, id) {
    return this.all(type).find((r) => r.recordId === Number(id));
  }
  related(type, alias, id) {
    const key = this.registry.field(type, alias);
    return this.all(type).filter((row) => String(row.data[key]) === String(id));
  }
  read(type, row, alias) {
    return this.registry.get(type, row.data, alias);
  }
  label(type, row) {
    for (const alias of ['NAME', 'COMPANY_NAME', 'REGISTRATION_NUMBER', 'CODE'])
      if (this.registry.fields(type)[alias] && this.read(type, row, alias))
        return this.read(type, row, alias);
    return '#' + row.recordId;
  }
  search(type, query) {
    const text = query.toLowerCase();
    return this.all(type).filter((row) =>
      Object.values(row.data).some((v) => String(v).toLowerCase().includes(text)),
    );
  }
}
