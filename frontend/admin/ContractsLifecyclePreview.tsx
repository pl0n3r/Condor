/// <reference types="vite/client" />
import { useEffect, useState } from 'react';
import { Card, MetricStrip, StatusPill } from './ui/Kit';

/** GEST-M02 S04–S08: sólo referencias ficticias; nunca tiene efectos laborales. */
export type LifecycleOptions = Readonly<{
  enabled: boolean; allowed: boolean; tenantId: string; companyId: string;
  branchId: string; csrf: string; referenceDate: string;
}>;
type Section = 'contracts' | 'signature_evidence' | 'renewals' | 'terminations' | 'settlement_drafts';
type Preview = Readonly<Record<Section, unknown[]>>;
type Result = Readonly<{ status: 'loading' | 'ready' | 'empty' | 'disabled' | 'denied' | 'error'; preview?: Preview }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;
const sections: readonly Section[] = ['contracts', 'signature_evidence', 'renewals', 'terminations', 'settlement_drafts'];
const titles = ['Contratos revisables', 'Evidencia sin firma válida', 'Renovaciones propuestas',
  'Alertas y terminaciones candidatas', 'Liquidaciones sin cuantía legal'];
const refPattern = /^fixture:(?:contract|worker|signature-evidence|reviewer|renewal|termination|settlement-draft)\/[a-z][a-z0-9_-]{0,47}$/;

function record(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}
function exact(value: unknown, names: readonly string[]): value is Record<string, unknown> {
  return record(value) && Object.keys(value).sort().join('|') === [...names].sort().join('|');
}
function ref(value: unknown): boolean {
  return typeof value === 'string' && refPattern.test(value) && !/[0-9]{7,}/.test(value);
}
function rows(value: unknown, keys: readonly string[], checks: (row: Record<string, unknown>) => boolean): boolean {
  return Array.isArray(value) && value.length <= 100
    && value.every(row => exact(row, keys) && checks(row))
    && new Set(value.map(row => row.ref)).size === value.length;
}
async function sha256(value: string): Promise<string> {
  const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
}
export async function syntheticLifecycleScope(tenantId: string, companyId: string, branchId: string) {
  const [tenantHash, branchHash] = await Promise.all([
    sha256(tenantId), sha256([tenantId, companyId, branchId].join('|')),
  ]);
  return { tenant_ref: 'tenant-test-t' + tenantHash.slice(0, 15),
    company_ref: 'fixture:company/c' + branchHash.slice(0, 15) };
}
function samplePayload(scope: { tenant_ref: string; company_ref: string }, date: string) {
  const ids = { contract: 'fixture:contract/demo', worker: 'fixture:worker/demo',
    reviewer: 'fixture:reviewer/demo', signature: 'fixture:signature-evidence/demo',
    renewal: 'fixture:renewal/demo', termination: 'fixture:termination/demo',
    settlement: 'fixture:settlement-draft/demo' };
  return { ...scope, reference_date: date,
    contracts: [{ ...scope, ref: ids.contract, worker_ref: ids.worker,
      start_on: '2026-01-01', end_on: '2030-12-31' }],
    signature_evidence: [{ ...scope, ref: ids.signature, contract_ref: ids.contract,
      reviewer_ref: ids.reviewer, captured_on: '2026-11-07' }],
    renewals: [{ ...scope, ref: ids.renewal, contract_ref: ids.contract,
      reviewer_ref: ids.reviewer, proposed_end_on: '2031-12-31' }],
    terminations: [{ ...scope, ref: ids.termination, contract_ref: ids.contract,
      reviewer_ref: ids.reviewer, effective_on: '2026-11-07', reason_key: 'TERM_REVIEW' }],
    settlement_drafts: [{ ...scope, ref: ids.settlement,
      termination_ref: ids.termination, reviewer_ref: ids.reviewer }],
  };
}
function validPreview(data: unknown, scope: { tenant_ref: string; company_ref: string }): data is Preview {
  if (!exact(data, ['valid', 'state', 'tenant_ref', 'company_ref', ...sections, 'error'])
    || data.valid !== true || data.state !== 'REQUIERE_REVISION' || data.error !== null
    || data.tenant_ref !== scope.tenant_ref || data.company_ref !== scope.company_ref) return false;
  return rows(data.contracts, ['ref', 'worker_ref', 'start_on', 'end_on', 'state',
    'signature_validated', 'closed', 'renewal_attention_candidate'], row =>
    ref(row.ref) && ref(row.worker_ref) && row.state === 'REQUIERE_REVISION'
    && row.signature_validated === false && row.closed === false
    && typeof row.renewal_attention_candidate === 'boolean')
    && rows(data.signature_evidence, ['ref', 'contract_ref', 'captured_on', 'state',
      'synthetic_evidence_only', 'signature_validated'], row => ref(row.ref)
      && ref(row.contract_ref) && row.state === 'REQUIERE_REVISION'
      && row.synthetic_evidence_only === true && row.signature_validated === false)
    && rows(data.renewals, ['ref', 'contract_ref', 'proposed_end_on', 'state',
      'applied', 'notification_sent'], row => ref(row.ref) && ref(row.contract_ref)
      && row.state === 'REQUIERE_REVISION' && row.applied === false
      && row.notification_sent === false)
    && rows(data.terminations, ['ref', 'contract_ref', 'effective_on', 'reason_key',
      'state', 'contract_closed'], row => ref(row.ref) && ref(row.contract_ref)
      && row.state === 'REQUIERE_REVISION' && row.contract_closed === false)
    && rows(data.settlement_drafts, ['ref', 'termination_ref', 'state', 'legal_value',
      'notice', 'amount_cop', 'approved'], row => ref(row.ref) && ref(row.termination_ref)
      && row.state === 'REQUIERE_REVISION' && row.legal_value === false
      && row.notice === 'SIN_VALOR_LEGAL' && row.amount_cop === null
      && row.approved === false);
}
export async function syntheticLifecycleRequest(options: LifecycleOptions,
  transport: typeof fetch = fetch): Promise<Result> {
  if (!import.meta.env.DEV || !options.enabled) return { status: 'disabled' };
  if (!options.allowed || options.companyId === '__unassigned__') return { status: 'denied' };
  if (![options.tenantId, options.companyId, options.branchId].every(value =>
    /^[a-zA-Z0-9_-]{2,64}$/.test(value)) || !options.csrf
    || !/^\d{4}-\d{2}-\d{2}$/.test(options.referenceDate)) return { status: 'error' };
  try {
    const scope = await syntheticLifecycleScope(options.tenantId, options.companyId, options.branchId);
    const response = await transport('/api/v1/branches/' + encodeURIComponent(options.branchId)
      + '/contracts/lifecycle/preview', { method: 'POST', credentials: 'same-origin',
      cache: 'no-store', headers: { Accept: 'application/json', 'Content-Type': 'application/json',
        'X-CSRF-Token': options.csrf }, body: JSON.stringify(samplePayload(scope, options.referenceDate)) });
    if (response.status === 401 || response.status === 403) return { status: 'denied' };
    if (response.status === 404) return { status: 'disabled' };
    if (!response.ok) return { status: 'error' };
    const body: unknown = await response.json();
    if (!exact(body, ['lifecycle_preview', 'synthetic_only', 'requires_human_review',
      'legal_value', 'signature_validated', 'notification_sent'])
      || body.synthetic_only !== true || body.requires_human_review !== true
      || body.legal_value !== false || body.signature_validated !== false
      || body.notification_sent !== false || !validPreview(body.lifecycle_preview, scope)) {
      return { status: 'error' };
    }
    const preview = body.lifecycle_preview;
    return { status: sections.every(section => preview[section].length === 0) ? 'empty' : 'ready', preview };
  } catch { return { status: 'error' }; }
}

/** Importable pero NO montado: integración SSR/bundle pertenece a otra hoja V3. */
export function ContractsLifecyclePreview({ tenantId, companyId, branchId, permissions }: Props) {
  const root = typeof document === 'undefined' ? null : document.getElementById('condor-admin-root');
  const enabled = import.meta.env.DEV && root?.dataset.contractsSyntheticApiEnabled === 'true';
  const allowed = permissions.includes('inventory.view') && companyId !== '__unassigned__';
  const [result, setResult] = useState<Result>({ status: 'loading' });
  const scope = [tenantId, companyId, branchId].join('|');
  useEffect(() => {
    if (!enabled || !allowed) return;
    let active = true;
    setResult({ status: 'loading' });
    void syntheticLifecycleRequest({ enabled, allowed, tenantId, companyId, branchId,
      csrf: root?.dataset.accessToken ?? '', referenceDate: new Date().toISOString().slice(0, 10) })
      .then(value => { if (active) setResult(value); });
    return () => { active = false; };
  }, [scope, enabled, allowed]);
  if (!enabled) return <section aria-label="Ciclo de contratación"><p role="status">Previsualización deshabilitada por el servidor.</p></section>;
  if (!allowed) return <section aria-label="Ciclo de contratación"><p role="alert">Sin permiso para consultar esta empresa.</p></section>;
  return <section aria-label="Ciclo de contratación" className="catalog-panel ui-page">
    <h3>Ciclo contractual · revisión manual</h3>
    <p>Solo referencias ficticias. No firma, modifica, notifica, termina ni liquida contratos reales.</p>
    {result.status === 'loading' && <p role="status">Cargando previsualización sintética…</p>}
    {result.status === 'error' && <p role="alert">No fue posible cargar una previsualización segura.</p>}
    {result.status === 'denied' && <p role="alert">Acceso denegado por el servidor.</p>}
    {result.status === 'disabled' && <p role="status">La función está deshabilitada.</p>}
    {result.status === 'empty' && <p role="status">No hay propuestas para revisar.</p>}
    {result.status === 'ready' && result.preview && <>
      <MetricStrip label="Elementos para revisión humana" items={sections.map((section, i) =>
        ({ label: titles[i], value: result.preview![section].length }))} />
      {sections.map((section, i) => <Card key={section} title={titles[i]}>
        <StatusPill tone="warn">Sin efecto jurídico</StatusPill>
        <p>{result.preview![section].length} referencia(s) de demostración. Requiere revisión humana.</p>
      </Card>)}
    </>}
  </section>;
}
