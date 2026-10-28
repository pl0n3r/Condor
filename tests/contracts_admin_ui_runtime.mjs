/** Behavior harness for real M02 DEV-only handlers. No network or React install. */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../frontend/admin/ContractsManagement.tsx', import.meta.url), 'utf8');
const scenario = process.argv[2];
const mutation = process.argv[3] === '--mutate-scope';
const constantsStart = source.indexOf('const permitted = {');
const functionStart = source.indexOf('function ContractPreview(');
const functionEnd = source.indexOf('  if (!canRead) return (', functionStart);
assert.ok(constantsStart >= 0 && functionStart > constantsStart && functionEnd > functionStart,
  'M02 handler boundaries must exist; do not silently skip behavioral tests');
let constants = source.slice(constantsStart, functionStart)
  .replace('} as const;', '};')
  .replace('const allowedType: readonly ContractType[] =', 'const allowedType =');
let handlers = source.slice(functionStart, functionEnd)
  .replace('}: Props) {', '}) {')
  .replace(/useState<(?:Draft\[\]|Note\[\]|string \| null|ContractType)>/g, 'useState')
  .replace(/(refToRecord|operation): string/g, '$1')
  .replace(/event: FormEvent<HTMLFormElement>/g, 'event')
  .replace(/item: Draft/g, 'item')
  .replace('const next: Phase =', 'const next =');
const originalFilter = 'drafts.filter(entry => entry.scope === scope)';
assert.ok(handlers.includes(originalFilter), 'scope filter must remain testable');
if (mutation) handlers = handlers.replace(originalFilter, 'drafts.filter(entry => true || entry.scope === scope)');
handlers += '  return { scope, canRead, canAdd, canAdvance, drafts, scopedDrafts, listing, selected,\n' +
  '    trail, notice, setRef, setFictionalPerson, setSourceTemplate, setCategory,\n' +
  '    createDemo, inspect, advance };\n}\n';
// Compile only the actual, TS-type-stripped behavior before JSX rendering. State setter
// semantics match functional React hooks. No untrusted payloads or external calls.
const Component = new Function('useState', constants + handlers + '\nreturn ContractPreview;');
function mount() {
  const state = [];
  let cursor = 0;
  function useState(initial) {
    const index = cursor++;
    if (!(index in state)) state[index] = initial;
    return [state[index], value => {
      state[index] = typeof value === 'function' ? value(state[index]) : value;
    }];
  }
  const renderImpl = Component(useState);
  return props => { cursor = 0; return renderImpl(props); };
}
const event = { preventDefault() {} };
const full = { tenantId: 'tenant-demo', companyId: 'company-A', branchId: 'branch-01',
  permissions: ['users.view', 'users.create', 'users.update'] };
const other = { ...full, companyId: 'company-B' };
const reference = 'fixture:contracts/demo-01';
function fill(render, props, { ref = reference, person = 'fixture:person/test-01',
  template = 'fixture:template/sample-01' } = {}) {
  const view = render(props);
  view.setRef(ref);
  view.setFictionalPerson(person);
  view.setSourceTemplate(template);
  return render(props);
}
function testScope() {
  const render = mount();
  fill(render, full).createDemo(event);
  let a = render(full);
  assert.equal(a.scopedDrafts.length, 1, 'scope A must create exactly one synthetic draft');
  assert.equal(a.trail.length, 1, 'creation must emit local audit');
  assert.equal(a.selected.phase, 'BORRADOR');
  const foreign = a.scopedDrafts[0];
  let b = render(other);
  assert.equal(b.scopedDrafts.length, 0, 'isolation: scope B must never list A');
  assert.equal(b.listing.length, 0, 'isolation: scope B cannot query A');
  assert.equal(b.selected, undefined, 'isolation: scope B cannot select A');
  b.inspect(foreign); // bypass attempt using the real source handler
  b.advance(event); // bypass attempt against A's stale selection
  b = render(other);
  assert.equal(b.trail.filter(note => note.scope === b.scope).length, 0,
    'isolation: no cross-scope audit of foreign records');
  a = render(full);
  assert.equal(a.selected.phase, 'BORRADOR', 'scope B cannot transition A');
  a.inspect(foreign);
  a = render(full);
  assert.equal(a.trail.at(-1).operation, 'consulta_ficticia');
  a.advance(event);
  a = render(full);
  assert.equal(a.selected.phase, 'REQUIERE_REVISION');
  a.advance(event);
  a = render(full);
  assert.equal(a.selected.phase, 'EN_REVISION');
  const before = a.trail.length;
  a.advance(event);
  assert.equal(render(full).trail.length, before, 'terminal test phase cannot advance');
  b = render(other);
  assert.equal(b.scopedDrafts.length, 0, 'isolation: transitions do not leak to B');
}
function testValidation() {
  const render = mount();
  const readOnly = { ...full, permissions: ['users.view'] };
  fill(render, readOnly).createDemo(event);
  assert.equal(render(readOnly).drafts.length, 0, 'create without users.create forbidden');
  const noRead = { ...full, permissions: ['users.create', 'users.update'] };
  fill(render, noRead).createDemo(event);
  assert.equal(render(noRead).drafts.length, 0, 'read permission prerequisite');
  const unassigned = { ...full, companyId: '__unassigned__' };
  fill(render, unassigned).createDemo(event);
  assert.equal(render(unassigned).drafts.length, 0, 'unassigned tenant company forbidden');
  fill(render, full, { person: 'maria@example.com' }).createDemo(event);
  assert.equal(render(full).drafts.length, 0, 'PII-like fixture forbidden');
  fill(render, full, { ref: 'fixture:contracts/../../secret' }).createDemo(event);
  assert.equal(render(full).drafts.length, 0, 'path-like fixture forbidden');
  fill(render, full).createDemo(event);
  let a = render(full);
  assert.equal(a.drafts.length, 1);
  fill(render, full).createDemo(event);
  assert.equal(render(full).drafts.length, 1, 'duplicate same-scope creation forbidden');
  const initialTrail = render(full).trail.length;
  render(readOnly).advance(event);
  assert.equal(render(full).selected.phase, 'BORRADOR', 'update without permission forbidden');
  assert.equal(render(full).trail.length, initialTrail, 'denial emits no false audit');
  render(noRead).advance(event);
  assert.equal(render(full).selected.phase, 'BORRADOR', 'update without read permission forbidden');
  a = render(full);
  a.advance(event);
  assert.equal(render(full).selected.phase, 'REQUIERE_REVISION');
  render(full).advance(event);
  assert.equal(render(full).selected.phase, 'EN_REVISION');
  const count = render(full).trail.length;
  render(full).advance(event);
  assert.equal(render(full).trail.length, count, 'terminal state cannot advance');
  assert.deepEqual(render(full).drafts.map(d => d.phase), ['EN_REVISION']);
}
if (scenario === 'scope') testScope();
else if (scenario === 'validation') testValidation();
else throw new Error('unknown scenario');
console.log('M02 behavioral scenario passed:', scenario);
