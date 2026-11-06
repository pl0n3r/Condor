import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar, type Tone } from './Kit';

/** Páginas de referencia del administrador: muestran cómo se arma un módulo con el kit (cabecera, métricas, filtros,
 *  tabla y panel lateral). Los datos son de ejemplo y viven en el navegador; cada módulo real los reemplaza por su API. */

type Person = Readonly<{ id: string; name: string; role: string; area: string; branch: string; state: 'Activo' | 'En vacaciones' | 'En revisión'; since: string }>;
const PEOPLE: readonly Person[] = [
  { id: 'C-0001', name: 'Laura Mejía', role: 'Jefa de ventas', area: 'Comercial', branch: 'Sede principal', state: 'Activo', since: '12 mar 2023' },
  { id: 'C-0002', name: 'Andrés Rojas', role: 'Analista de inventario', area: 'Operaciones', branch: 'Sede principal', state: 'Activo', since: '03 jul 2023' },
  { id: 'C-0003', name: 'Camila Duarte', role: 'Contadora', area: 'Finanzas', branch: 'Sede principal', state: 'En vacaciones', since: '21 ene 2022' },
  { id: 'C-0004', name: 'Sebastián Parra', role: 'Auxiliar de bodega', area: 'Operaciones', branch: 'Bodega norte', state: 'Activo', since: '15 sep 2024' },
  { id: 'C-0005', name: 'Valentina Ortiz', role: 'Coordinadora de talento', area: 'Talento humano', branch: 'Sede principal', state: 'Activo', since: '08 may 2021' },
  { id: 'C-0006', name: 'Julián Torres', role: 'Asesor comercial', area: 'Comercial', branch: 'Bodega norte', state: 'En revisión', since: '02 feb 2026' },
  { id: 'C-0007', name: 'Daniela Cruz', role: 'Compradora', area: 'Compras', branch: 'Sede principal', state: 'Activo', since: '19 oct 2022' },
  { id: 'C-0008', name: 'Mateo Salazar', role: 'Soporte al cliente', area: 'Comercial', branch: 'Sede principal', state: 'Activo', since: '27 nov 2025' },
];
const personTone: Record<Person['state'], Tone> = { Activo: 'ok', 'En vacaciones': 'info', 'En revisión': 'warn' };

export function PeoplePage() {
  const [search, setSearch] = useState('');
  const [state, setState] = useState('Todos');
  const [area, setArea] = useState('Todas');
  const [selected, setSelected] = useState<Person | null>(null);
  const [creating, setCreating] = useState(false);
  const [notice, setNotice] = useState('');

  const rows = useMemo(() => PEOPLE.filter((p) =>
    (state === 'Todos' || p.state === state)
    && (area === 'Todas' || p.area === area)
    && (search === '' || (p.name + ' ' + p.role + ' ' + p.id).toLowerCase().includes(search.toLowerCase()))), [search, state, area]);

  function save(event: FormEvent) {
    event.preventDefault();
    setCreating(false);
    setNotice('Simulación completada: no se guardó información.');
  }

  return (
    <div className="ui-page">
      <PageHeader
        title="Colaboradores"
        description="Personas de la empresa, su cargo, su sede y su situación actual."
        actions={<>
          <button type="button" className="button button-secondary" disabled title="Solo demostración">Exportar</button>
          <button type="button" className="button" onClick={() => setCreating(true)}>Nuevo colaborador</button>
        </>}
      />
      <MetricStrip label="Resumen de colaboradores" items={[
        { label: 'Colaboradores', value: 48, hint: '+2 este mes', tone: 'ok' },
        { label: 'En vacaciones', value: 3 },
        { label: 'Novedades abiertas', value: 6, hint: '2 por revisar', tone: 'warn' },
        { label: 'Movimientos del mes', value: 3 },
      ]} />
      {notice && <output className="alert alert-success">{notice}</output>}
      <Card>
        <Toolbar trailing={<span className="ui-count">{rows.length} de {PEOPLE.length}</span>}>
          <label className="ui-search"><span className="ui-sr-only">Buscar</span>
            <input type="search" placeholder="Buscar por nombre, cargo o código" value={search} onChange={(e) => setSearch(e.target.value)} />
          </label>
          <label><span className="ui-sr-only">Estado</span>
            <select value={state} onChange={(e) => setState(e.target.value)}>
              {['Todos', 'Activo', 'En vacaciones', 'En revisión'].map((s) => <option key={s}>{s}</option>)}
            </select>
          </label>
          <label><span className="ui-sr-only">Área</span>
            <select value={area} onChange={(e) => setArea(e.target.value)}>
              {['Todas', 'Comercial', 'Operaciones', 'Finanzas', 'Talento humano', 'Compras'].map((s) => <option key={s}>{s}</option>)}
            </select>
          </label>
        </Toolbar>
        <DataTable
          caption="Colaboradores"
          rows={rows}
          rowKey={(p) => p.id}
          onOpen={setSelected}
          empty={<><strong>Sin resultados</strong><span>Prueba con otro nombre o quita un filtro.</span></>}
          columns={[
            { key: 'name', header: 'Colaborador', render: (p) => <span className="ui-cell-main"><strong>{p.name}</strong><small>{p.id}</small></span> },
            { key: 'role', header: 'Cargo', render: (p) => p.role },
            { key: 'area', header: 'Área', render: (p) => p.area },
            { key: 'branch', header: 'Sede', render: (p) => p.branch },
            { key: 'state', header: 'Estado', render: (p) => <StatusPill tone={personTone[p.state]}>{p.state}</StatusPill> },
            { key: 'since', header: 'Ingreso', render: (p) => p.since, align: 'right' },
          ]}
        />
      </Card>

      <SidePanel open={selected !== null} title={selected?.name ?? ''} onClose={() => setSelected(null)}
        footer={<><button type="button" className="button button-secondary" onClick={() => setSelected(null)}>Cerrar</button><button type="button" className="button" disabled title="Solo demostración">Registrar novedad</button></>}>
        {selected && (
          <dl className="ui-details">
            <div><dt>Código</dt><dd>{selected.id}</dd></div>
            <div><dt>Cargo</dt><dd>{selected.role}</dd></div>
            <div><dt>Área</dt><dd>{selected.area}</dd></div>
            <div><dt>Sede</dt><dd>{selected.branch}</dd></div>
            <div><dt>Estado</dt><dd><StatusPill tone={personTone[selected.state]}>{selected.state}</StatusPill></dd></div>
            <div><dt>Ingreso</dt><dd>{selected.since}</dd></div>
          </dl>
        )}
      </SidePanel>

      <SidePanel open={creating} title="Nuevo colaborador" onClose={() => setCreating(false)}
        footer={<><button type="button" className="button button-secondary" onClick={() => setCreating(false)}>Cancelar</button><button type="submit" form="nuevo-colaborador" className="button">Guardar</button></>}>
        <form id="nuevo-colaborador" onSubmit={save}>
          <label className="field">Nombre completo<input required placeholder="Ej.: María Fernanda López" /></label>
          <label className="field">Cargo<input required placeholder="Ej.: Analista contable" /></label>
          <div className="ui-two">
            <label className="field">Área<select>{['Comercial', 'Operaciones', 'Finanzas', 'Talento humano', 'Compras'].map((s) => <option key={s}>{s}</option>)}</select></label>
            <label className="field">Sede<select><option>Sede principal</option><option>Bodega norte</option></select></label>
          </div>
          <label className="field">Fecha de ingreso<input type="date" required /></label>
        </form>
      </SidePanel>
    </div>
  );
}

type Order = Readonly<{ id: string; customer: string; date: string; channel: string; total: number; state: 'Pagado' | 'Pendiente de pago' | 'En preparación' | 'Entregado' | 'Cancelado' }>;
const ORDERS: readonly Order[] = [
  { id: 'P-10428', customer: 'Distribuidora Andina S. A. S.', date: 'Hoy, 9:14 a. m.', channel: 'Tienda web', total: 1840000, state: 'Pagado' },
  { id: 'P-10427', customer: 'Panadería La Esperanza', date: 'Hoy, 8:40 a. m.', channel: 'Mostrador', total: 265500, state: 'En preparación' },
  { id: 'P-10426', customer: 'Ferretería El Tornillo', date: 'Ayer, 5:02 p. m.', channel: 'Asesor', total: 3120000, state: 'Pendiente de pago' },
  { id: 'P-10425', customer: 'Restaurante Sabor Criollo', date: 'Ayer, 3:31 p. m.', channel: 'Tienda web', total: 742300, state: 'Entregado' },
  { id: 'P-10424', customer: 'Papelería Central', date: 'Ayer, 11:08 a. m.', channel: 'Mostrador', total: 98000, state: 'Cancelado' },
  { id: 'P-10423', customer: 'Almacén Los Pinos', date: '3 nov, 4:20 p. m.', channel: 'Asesor', total: 1275000, state: 'Entregado' },
];
const orderTone: Record<Order['state'], Tone> = { Pagado: 'ok', 'Pendiente de pago': 'warn', 'En preparación': 'info', Entregado: 'neutral', Cancelado: 'danger' };
const cop = (value: number) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(value);

export function OrdersPage() {
  const [search, setSearch] = useState('');
  const [state, setState] = useState('Todos');
  const rows = useMemo(() => ORDERS.filter((o) => (state === 'Todos' || o.state === state)
    && (search === '' || (o.customer + ' ' + o.id).toLowerCase().includes(search.toLowerCase()))), [search, state]);
  return (
    <div className="ui-page">
      <PageHeader
        title="Pedidos"
        description="Pedidos de todos los canales de venta, con su pago y su preparación."
        actions={<>
          <button type="button" className="button button-secondary" disabled title="Solo demostración">Exportar CSV</button>
          <button type="button" className="button" disabled title="Solo demostración">Nuevo pedido</button>
        </>}
      />
      <MetricStrip label="Resumen de pedidos" items={[
        { label: 'Pedidos de hoy', value: 12, hint: '+3 frente a ayer', tone: 'ok' },
        { label: 'Pendientes de pago', value: 4, hint: cop(5240000), tone: 'warn' },
        { label: 'En preparación', value: 5 },
        { label: 'Ventas del día', value: cop(4105500) },
      ]} />
      <Card>
        <Toolbar trailing={<span className="ui-count">{rows.length} de {ORDERS.length}</span>}>
          <label className="ui-search"><span className="ui-sr-only">Buscar</span>
            <input type="search" placeholder="Buscar por cliente o número" value={search} onChange={(e) => setSearch(e.target.value)} />
          </label>
          <label><span className="ui-sr-only">Estado</span>
            <select value={state} onChange={(e) => setState(e.target.value)}>
              {['Todos', 'Pagado', 'Pendiente de pago', 'En preparación', 'Entregado', 'Cancelado'].map((s) => <option key={s}>{s}</option>)}
            </select>
          </label>
        </Toolbar>
        <DataTable
          caption="Pedidos"
          rows={rows}
          rowKey={(o) => o.id}
          empty={<><strong>Sin pedidos</strong><span>Cambia el filtro o crea un pedido nuevo.</span></>}
          columns={[
            { key: 'id', header: 'Pedido', render: (o) => <strong>{o.id}</strong> },
            { key: 'customer', header: 'Cliente', render: (o) => o.customer },
            { key: 'date', header: 'Fecha', render: (o) => o.date },
            { key: 'channel', header: 'Canal', render: (o) => o.channel },
            { key: 'state', header: 'Estado', render: (o) => <StatusPill tone={orderTone[o.state]}>{o.state}</StatusPill> },
            { key: 'total', header: 'Total', render: (o) => cop(o.total), align: 'right' },
          ]}
        />
      </Card>
    </div>
  );
}

/** Inicio: lo que necesita atención hoy, accesos directos y actividad reciente. */
export function DashboardPage({ companyName }: Readonly<{ companyName: string }>) {
  return (
    <div className="ui-page">
      <PageHeader title="Resumen del día" description={'Lo que necesita atención hoy en ' + companyName + '.'} />
      <MetricStrip label="Resumen de la empresa" items={[
        { label: 'Ventas del día', value: cop(4105500), hint: '+8 % frente a ayer', tone: 'ok' },
        { label: 'Pedidos por atender', value: 9, hint: '4 sin pago', tone: 'warn' },
        { label: 'Productos con stock bajo', value: 7, hint: 'Ver alertas', tone: 'danger' },
        { label: 'Colaboradores', value: 48 },
      ]} />
      <div className="ui-grid-2">
        <Card title="Por hacer">
          <ul className="ui-tasks">
            <li><a href="/admin#orders"><strong>4 pedidos pendientes de pago</strong><span>El más antiguo lleva 2 días.</span></a></li>
            <li><a href="/admin#stock-alerts"><strong>7 productos bajo el mínimo</strong><span>Revisa las alertas de stock y crea compras.</span></a></li>
            <li><a href="/admin#human-resources-management"><strong>2 novedades por revisar</strong><span>Talento humano · sede principal.</span></a></li>
            <li><a href="/admin#purchasing"><strong>1 orden de compra por aprobar</strong><span>Proveedor: Insumos del Valle.</span></a></li>
          </ul>
        </Card>
        <Card title="Accesos rápidos">
          <div className="ui-shortcuts">
            <a className="button" href="/admin#orders">Nuevo pedido</a>
            <a className="button button-secondary" href="/admin#catalog">Nuevo producto</a>
            <a className="button button-secondary" href="/admin#purchasing">Registrar compra</a>
            <a className="button button-secondary" href="/admin#human-resources-management">Nuevo colaborador</a>
          </div>
          <p className="ui-note">Los datos de esta página son de ejemplo.</p>
        </Card>
      </div>
    </div>
  );
}

/** Exclusivamente en Vite DEV: no hay persistencia, efectos de red ni permisos nuevos. */
type ReferenceScreen = 'dashboard' | 'people' | 'orders';
export function DesignPreview() {
  const [screen, setScreen] = useState<ReferenceScreen>('dashboard');
  const root = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const container = root.current;
    if (!container || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    let disposed = false;
    let context: { revert: () => void } | undefined;
    void import('gsap').then(({ gsap }) => {
      if (disposed) return;
      context = gsap.context(() => {
        gsap.fromTo('.ui-page-header, .ui-metric',
          { opacity: 0, y: 8 },
          { opacity: 1, y: 0, duration: 0.24, stagger: 0.035, ease: 'power1.out', clearProps: 'opacity,transform' });
      }, container);
    });
    return () => { disposed = true; context?.revert(); };
  }, [screen]);

  return (
    <div className="ui-preview" ref={root}>
      <div className="ui-preview-banner" role="note">
        <strong>Laboratorio de diseño</strong>
        <span>Solo desarrollo · datos ficticios · sin escrituras ni integraciones externas.</span>
      </div>
      <nav className="ui-preview-tabs" aria-label="Pantallas de referencia">
        {([
          ['dashboard', 'Inicio'],
          ['people', 'Colaboradores'],
          ['orders', 'Pedidos'],
        ] as const).map(([key, label]) => (
          <button key={key} type="button" className="ui-preview-tab"
            aria-pressed={screen === key} onClick={() => setScreen(key)}>{label}</button>
        ))}
      </nav>
      {screen === 'dashboard' && <DashboardPage companyName="Empresa de ejemplo" />}
      {screen === 'people' && <PeoplePage />}
      {screen === 'orders' && <OrdersPage />}
    </div>
  );
}
