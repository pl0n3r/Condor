import { useEffect, useState } from 'react';
import { Card, DataTable, PageHeader, StatusPill, Toolbar } from './ui/Kit';

/**
 * Only the ten synthetic Symfony M01 catalogs. No employee/attendance API,
 * persistence, personal identifiers, HR_REAL_DATA_ENABLED or write methods.
 * The actual server authorizes the branch and legal entity on every GET.
 */
export const M01_CATALOGS = [
  { id: 'S02', label: 'Cargos', path: 'positions', field: 'positions' },
  { id: 'S03', label: 'Áreas', path: 'areas', field: 'areas' },
  { id: 'S04', label: 'Sedes', path: 'branch-preview', field: 'branches' },
  { id: 'S05', label: 'Organigrama', path: 'org-chart', field: 'nodes' },
  { id: 'S06', label: 'Novedades', path: 'changes', field: 'changes' },
  { id: 'S07', label: 'Permisos', path: 'permits', field: 'permits' },
  { id: 'S08', label: 'Vacaciones', path: 'vacations', field: 'vacations' },
  { id: 'S09', label: 'Incapacidades', path: 'incapacities', field: 'incapacities' },
  { id: 'S10', label: 'Evaluaciones', path: 'evaluations', field: 'evaluations' },
  { id: 'S11', label: 'Portal ficticio', path: 'employee-portal', field: 'items' },
] as const;

export type M01Catalog = (typeof M01_CATALOGS)[number];
export type M01Row = Readonly<{ ref: string; label: string }>;
export type M01CatalogResult =
  | { status: 'ready'; rows: M01Row[] }
  | { status: 'denied' | 'disabled' | 'error'; rows: [] };

type Scope = Readonly<{
  tenantId: string;
  companyId: string;
  branchId: string;
  allowed: boolean;
  signal?: AbortSignal;
}>;
type Props = Readonly<{
  tenantId: string;
  companyId: string;
  branchId: string;
  permissions: readonly string[];
}>;
type State = M01CatalogResult | { status: 'loading'; rows: [] };

const isRecord = (value: unknown): value is Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value);
const ulid = /^[0-9A-HJKMNP-TV-Z]{26}$/;

/** Reject non-synthetic data and any reference exposing an entity identifier. */
export async function readM01SyntheticCatalog(
  scope: Scope,
  catalog: M01Catalog,
  transport: typeof fetch = fetch,
): Promise<M01CatalogResult> {
  if (!scope.allowed) return { status: 'denied', rows: [] };
  if (![scope.tenantId, scope.companyId, scope.branchId].every(id => ulid.test(id))) {
    return { status: 'error', rows: [] };
  }
  const url = '/api/v1/branches/' + encodeURIComponent(scope.branchId)
    + '/hr/' + catalog.path + '?company_id=' + encodeURIComponent(scope.companyId);
  try {
    const response = await transport(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
      signal: scope.signal,
    });
    if (response.status === 401 || response.status === 403) {
      return { status: 'denied', rows: [] };
    }
    if (response.status === 404) return { status: 'disabled', rows: [] };
    if (!response.ok) return { status: 'error', rows: [] };
    const payload: unknown = await response.json();
    if (!isRecord(payload) || payload.synthetic !== true || payload.persisted !== false
      || payload.juridically_binding !== false || !Array.isArray(payload[catalog.field])
      || payload[catalog.field].length > 100) {
      return { status: 'error', rows: [] };
    }
    const seen = new Set<string>();
    const rows: M01Row[] = [];
    for (const value of payload[catalog.field] as unknown[]) {
      if (!isRecord(value) || value.synthetic !== true || typeof value.ref !== 'string'
        || value.ref.length < 8 || value.ref.length > 128
        || !/^[a-z0-9_:-]+$/i.test(value.ref)
        || [scope.tenantId, scope.companyId, scope.branchId].some(id => value.ref.includes(id))
        || seen.has(value.ref)
        || (value.label !== undefined && (typeof value.label !== 'string' || value.label.length > 120))) {
        return { status: 'error', rows: [] };
      }
      seen.add(value.ref);
      rows.push({ ref: value.ref, label: typeof value.label === 'string'
        ? value.label : 'Elemento ficticio sin nombre' });
    }
    return { status: 'ready', rows };
  } catch {
    return { status: 'error', rows: [] };
  }
}

/** Read-only UI reachable only through the admin's explicit synthetic opt-in. */
export function HrM01SyntheticCatalogPanel({ tenantId, companyId, branchId, permissions }: Props) {
  const [id, setId] = useState<M01Catalog['id']>('S02');
  const [refresh, setRefresh] = useState(0);
  const [result, setResult] = useState<State>({ status: 'loading', rows: [] });
  const catalog = M01_CATALOGS.find(item => item.id === id) ?? M01_CATALOGS[0];
  const allowed = permissions.includes('users.view');

  useEffect(() => {
    const abort = new AbortController();
    setResult({ status: 'loading', rows: [] });
    void readM01SyntheticCatalog({
      tenantId, companyId, branchId, allowed, signal: abort.signal,
    }, catalog).then(next => {
      if (!abort.signal.aborted) setResult(next);
    });
    return () => abort.abort();
  }, [tenantId, companyId, branchId, allowed, catalog, refresh]);

  const rows = result.status === 'ready' ? result.rows : [];
  return <section className="ui-page" aria-label="Catálogos sintéticos M01">
    <PageHeader title="Catálogos de talento humano · pruebas"
      description="Vista de solo lectura. Datos ficticios sin efectos laborales ni jurídicos." />
    <StatusPill tone="info">Simulación de desarrollo, sin datos reales</StatusPill>
    <Card title="Consultar submódulo">
      <Toolbar trailing={<button type="button" className="button button-secondary"
        onClick={() => setRefresh(n => n + 1)}>Reintentar catálogo</button>}>
        <label>Submódulo M01
          <select value={id} onChange={event => setId(event.target.value as M01Catalog['id'])}>
            {M01_CATALOGS.map(item => <option key={item.id} value={item.id}>
              {item.id} · {item.label}
            </option>)}
          </select>
        </label>
      </Toolbar>
      {result.status === 'loading' && <output aria-live="polite">Cargando catálogo sintético…</output>}
      {result.status === 'denied' && <p role="alert">Acceso denegado: falta permiso para esta empresa o sede.</p>}
      {result.status === 'disabled' && <p role="status">Catálogo sintético no disponible en este servidor.</p>}
      {result.status === 'error' && <p role="alert">No se pudo consultar el catálogo sintético.</p>}
      {result.status === 'ready' && <DataTable<M01Row>
        caption={'Catálogo sintético de ' + catalog.label}
        rows={rows} rowKey={row => row.ref}
        columns={[
          { key: 'label', header: 'Descripción ficticia', render: row => row.label },
          { key: 'ref', header: 'Referencia opaca', render: row => <code>{row.ref}</code> },
        ]}
        empty={<output>No hay registros ficticios para este ámbito.</output>}
      />}
    </Card>
  </section>;
}
