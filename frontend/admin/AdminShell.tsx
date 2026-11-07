import { lazy, Suspense, useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { PlatformAuditPanel } from './PlatformAuditPanel';

/** El laboratorio solo existe en desarrollo; no activa datos ficticios en producción. */
const DesignReference = import.meta.env.DEV
  ? lazy(async () => ({ default: (await import('./ui/ReferencePages')).DesignPreview }))
  : null;

export type AdminNavItem = Readonly<{
  href: string;
  label: string;
  current?: boolean;
  icon?: NavIconName;
}>;


export type NavIconName =
  | 'home' | 'sales' | 'catalog' | 'site' | 'people' | 'finance' | 'operations' | 'admin';

/** Iconos propios de trazo uniforme (24x24, 1.75): geometría simple, sin recursos externos. */
const NAV_ICON_PATHS: Record<NavIconName, string> = {
  home: 'M3 11.5 12 4l9 7.5M5.5 10v9.5h13V10M10 19.5v-5h4v5',
  sales: 'M4 5h2l2.2 9.5h9.3L19.5 8H7M9.5 19.5h.01M17 19.5h.01',
  catalog: 'M12 3 4 7v10l8 4 8-4V7l-8-4ZM4 7l8 4 8-4M12 11v10',
  site: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9S9.5 5.5 12 3Z',
  people: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM2.5 20c.4-3.3 3.1-5.5 6.5-5.5s6.1 2.2 6.5 5.5M16 4.3a3.5 3.5 0 0 1 0 6.4M17.5 14.7c2.2.5 3.7 2.4 4 5.3',
  finance: 'M3 9.5 12 4l9 5.5M5 10v8M9.7 10v8M14.3 10v8M19 10v8M3 20h18',
  operations: 'M5 4h5v5H5V4ZM14 15h5v5h-5v-5ZM10 6.5h3a2.5 2.5 0 0 1 2.5 2.5v3.5M14 17.5h-3A2.5 2.5 0 0 1 8.5 15v-3',
  admin: 'M12 3 5 6v5.5c0 4.2 2.8 7.4 7 9 4.2-1.6 7-4.8 7-9V6l-7-3ZM9.5 12l2 2 3.5-4',
};

function NavIcon({ name }: Readonly<{ name?: NavIconName }>) {
  if (!name) return null;
  return (
    <svg className="nav-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"
      fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d={NAV_ICON_PATHS[name]} />
    </svg>
  );
}

/** Grupo de menú con submenú (se muestra plegable). */
export type AdminNavGroup = Readonly<{
  group: string;
  icon?: NavIconName;
  items: readonly AdminNavItem[];
}>;

export type AdminNavEntry = AdminNavItem | AdminNavGroup;

function itemIsCurrent(item: AdminNavItem, hash: string): boolean {
  if (item.current) return true;
  const at = item.href.indexOf('#');
  return at >= 0 && hash !== '' && item.href.slice(at) === hash;
}

/** Grupo plegable: el panel anima su altura con grid-template-rows (sin medir) y el chevron se voltea. */
function NavGroup({ entry, hash }: Readonly<{ entry: AdminNavGroup; hash: string }>) {
  const hasCurrent = entry.items.some((item) => itemIsCurrent(item, hash));
  const [open, setOpen] = useState(hasCurrent);
  const panelId = useId();

  useEffect(() => {
    if (hasCurrent) setOpen(true);
  }, [hasCurrent]);

  return (
    <div className="nav-group t-acc" data-open={open ? 'true' : 'false'}>
      <button
        type="button"
        className="nav-group-head t-acc-head"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((value) => !value)}
      >
        <NavIcon name={entry.icon} />
        <span>{entry.group}</span>
        <span className="t-acc-chevron" aria-hidden="true">
          <svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor"
            strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 6.5L8 10.5L12 6.5" />
          </svg>
        </span>
      </button>
      <div className="t-acc-panel" id={panelId} inert={!open || undefined}>
        <div className="t-acc-panel-inner">
          {entry.items.map((item) => (
            <a
              className="nav-sub"
              href={item.href}
              aria-current={itemIsCurrent(item, hash) ? 'page' : undefined}
              key={item.href}
            >
              <span>{item.label}</span>
            </a>
          ))}
        </div>
      </div>
    </div>
  );
}

function useLocationHash(): string {
  const [hash, setHash] = useState(() => (typeof window === 'undefined' ? '' : window.location.hash));
  useEffect(() => {
    const onChange = () => setHash(window.location.hash);
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return hash;
}

function isGroup(entry: AdminNavEntry): entry is AdminNavGroup {
  return 'group' in entry;
}

/** Enlace suelto, compatible con PlatformOwnerApp; el estado actual conserva su semántica ARIA. */
function StandaloneNavLink({ item }: Readonly<{ item: AdminNavItem }>) {
  return (
    <a href={item.href} aria-current={item.current ? 'page' : undefined}>
      <NavIcon name={item.icon} />
      <span>{item.label}</span>
    </a>
  );
}

type AdminShellProps = Readonly<{
  version: string;
  logoutToken: string;
  ariaLabel: string;
  navLabel: string;
  navItems: readonly AdminNavItem[] | readonly AdminNavEntry[];
  variant?: 'tenant' | 'platform';
  children: ReactNode;
}>;

type SearchItem = Readonly<{
  id: string;
  title: string;
  subtitle: string;
  href: string;
}>;

type SearchGroup = 'products' | 'customers' | 'orders';
type SearchResults = Record<SearchGroup, SearchItem[]>;
type SearchStatus = 'idle' | 'loading' | 'ready' | 'empty' | 'error';

const SEARCH_GROUPS: ReadonlyArray<{ key: SearchGroup; label: string }> = [
  { key: 'products', label: 'Productos' },
  { key: 'customers', label: 'Clientes' },
  { key: 'orders', label: 'Pedidos' },
];

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** Trust neither the payload shape nor navigation URLs returned by the API. */
function decodeSearchResults(payload: unknown): SearchResults {
  if (!isRecord(payload) || !isRecord(payload.results)) {
    throw new Error('Formato de búsqueda inválido');
  }

  const groups: SearchResults = { products: [], customers: [], orders: [] };
  for (const { key } of SEARCH_GROUPS) {
    const entries = payload.results[key];
    if (!Array.isArray(entries)) {
      throw new Error('Grupo de búsqueda inválido');
    }
    for (const entry of entries.slice(0, 5) as unknown[]) {
      if (
        !isRecord(entry) ||
        typeof entry.id !== 'string' ||
        typeof entry.title !== 'string' ||
        typeof entry.subtitle !== 'string' ||
        typeof entry.href !== 'string' ||
        !/^\/admin(?:\/|#|$)/.test(entry.href) ||
        entry.href.startsWith('//')
      ) {
        throw new Error('Resultado de búsqueda inválido');
      }
      groups[key].push({
        id: entry.id,
        title: entry.title,
        subtitle: entry.subtitle,
        href: entry.href,
      });
    }
  }
  return groups;
}

function AdminGlobalSearch() {
  const fieldId = useId();
  const resultsId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const resultsRef = useRef<HTMLDivElement>(null);
  const [query, setQuery] = useState('');
  const [visible, setVisible] = useState(false);
  const [status, setStatus] = useState<SearchStatus>('idle');
  const [error, setError] = useState('');
  const [results, setResults] = useState<SearchResults | null>(null);

  useEffect(() => {
    function onShortcut(event: globalThis.KeyboardEvent) {
      if (event.key !== '/' || event.altKey || event.ctrlKey || event.metaKey) {
        return;
      }
      const target = event.target;
      if (
        target instanceof HTMLInputElement ||
        target instanceof HTMLTextAreaElement ||
        target instanceof HTMLSelectElement ||
        (target instanceof HTMLElement && target.isContentEditable)
      ) {
        return;
      }
      event.preventDefault();
      inputRef.current?.focus();
      setVisible(true);
    }
    document.addEventListener('keydown', onShortcut);
    return () => document.removeEventListener('keydown', onShortcut);
  }, []);

  useEffect(() => {
    const term = query.trim();
    setResults(null);
    setError('');
    if (term.length < 2) {
      setStatus('idle');
      return;
    }
    if (term.length > 64 || !/^[\p{L}\p{N} .-]+$/u.test(term)) {
      setStatus('error');
      setError('Usa entre 2 y 64 caracteres: letras, números, espacios, puntos o guiones.');
      return;
    }

    const controller = new AbortController();
    setStatus('loading');
    const timer = window.setTimeout(() => {
      void (async () => {
        try {
          const response = await fetch('/api/admin/search?q=' + encodeURIComponent(term), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
          });
          if (!response.ok) {
            throw new Error('Solicitud de búsqueda fallida');
          }
          const found = decodeSearchResults(await response.json());
          if (controller.signal.aborted) {
            return;
          }
          setResults(found);
          setStatus(SEARCH_GROUPS.some(({ key }) => found[key].length > 0) ? 'ready' : 'empty');
        } catch {
          if (!controller.signal.aborted) {
            setResults(null);
            setError('No se pudo completar la búsqueda. Intenta nuevamente.');
            setStatus('error');
          }
        }
      })();
    }, 350);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [query]);

  function onKeyboard(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape') {
      inputRef.current?.focus();
      setVisible(false);
      return;
    }
    const links = Array.from(
      resultsRef.current?.querySelectorAll<HTMLAnchorElement>('a[data-search-result]') ?? [],
    );
    if (links.length === 0) {
      return;
    }
    if (event.key === 'Enter' && document.activeElement === inputRef.current) {
      event.preventDefault();
      links[0].click();
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
      return;
    }
    event.preventDefault();
    const index = links.findIndex((link) => link === document.activeElement);
    const next = event.key === 'ArrowDown'
      ? (index + 1) % links.length
      : (index < 0 ? links.length - 1 : (index - 1 + links.length) % links.length);
    links[next].focus();
  }

  return (
    <div className="workspace-heading" role="search" onKeyDown={onKeyboard}>
      <div className="field">
        <label htmlFor={fieldId}>Búsqueda global <small>(/)</small></label>
        <input
          ref={inputRef}
          id={fieldId}
          type="search"
          aria-label="Búsqueda global"
          aria-keyshortcuts="/"
          aria-controls={visible && query.trim().length >= 2 ? resultsId : undefined}
          autoComplete="off"
          maxLength={64}
          placeholder="Productos, clientes o pedidos"
          value={query}
          onChange={(event) => {
            setQuery(event.target.value);
            setVisible(true);
          }}
          onFocus={() => setVisible(true)}
        />
      </div>
      {visible && query.trim().length >= 2 && (
        <div id={resultsId} ref={resultsRef} role="region" aria-label="Resultados de búsqueda" aria-live="polite">
          {status === 'loading' && <p role="status">Buscando…</p>}
          {status === 'empty' && <p role="status">Sin resultados.</p>}
          {status === 'error' && <p role="alert">{error}</p>}
          {status === 'ready' && results && SEARCH_GROUPS.map(({ key, label }) => (
            results[key].length > 0 && (
              <section key={key} aria-label={label}>
                <h3>{label}</h3>
                <ul>
                  {results[key].map((item) => (
                    <li key={key + ':' + item.id}>
                      <a data-search-result href={item.href}>
                        <strong>{item.title}</strong> <small>{item.subtitle}</small>
                      </a>
                    </li>
                  ))}
                </ul>
              </section>
            )
          ))}
        </div>
      )}
    </div>
  );
}

export function AdminShell({
  version,
  logoutToken,
  ariaLabel,
  navLabel,
  navItems,
  variant = 'tenant',
  children,
}: AdminShellProps) {
  const hash = useLocationHash();
  const designPreview = import.meta.env.DEV && variant === 'tenant' && hash === '#design-preview';
  // La ruta del propietario ya está protegida en el servidor; solo la vista
  // de auditoría usa su consulta, sin ampliar permisos ni tocar OwnerApp.
  const auditView = variant === 'platform'
    && window.location.pathname === '/adminpl0n3r'
    && new URLSearchParams(window.location.search).get('view') === 'auditoria';
  const visibleNav: readonly AdminNavEntry[] = variant === 'platform'
    ? [
      ...navItems.map((entry) => isGroup(entry)
        ? {
          ...entry,
          items: entry.items.map((item) => ({ ...item, current: auditView ? false : item.current })),
        }
        : { ...entry, current: auditView ? false : entry.current }),
      {
        href: '/adminpl0n3r?view=auditoria',
        label: 'Auditoría',
        icon: 'admin',
        current: auditView,
      },
    ]
    : navItems;
  // Si el ancla de la URL apunta a una sección dentro de un grupo, esa sección es la actual y «Inicio» deja de estarlo.
  const sectionInGroup = visibleNav.some((entry) => isGroup(entry)
    && entry.items.some((item) => !item.current && itemIsCurrent(item, hash)));
  // Los grupos del propietario son rutas completas; los del tenant son anclas.
  // Conservar la selección anterior del tenant y reconocer current en plataforma.
  const isActiveGroupItem = (item: AdminNavItem): boolean => (
    variant === 'platform' ? item.current === true : !item.current && itemIsCurrent(item, hash)
  );
  let activeGroup = auditView ? 'Plataforma' : '';
  let activeLabel = auditView ? 'Auditoría' : '';
  let activeId = auditView ? 'auditoria' : '';
  for (const entry of visibleNav) {
    if (!isGroup(entry)) continue;
    const hit = entry.items.find(isActiveGroupItem);
    if (hit) {
      activeGroup = entry.group;
      activeLabel = hit.label.replace(' (pruebas)', '');
      activeId = variant === 'platform'
        ? (hit.href.startsWith('/adminpl0n3r/')
          ? hit.href.slice('/adminpl0n3r/'.length)
          : 'inicio')
        : hit.href.slice(hit.href.indexOf('#') + 1);
    }
  }
  return (
    <section
      className={'admin-shell admin-shell-' + variant}
      aria-label={ariaLabel}
    >
      <aside className="sidebar">
        <div className="brand-block">
          <strong>Condor App</strong>
          <span>V {version}</span>
        </div>

        <nav aria-label={navLabel}>
          {visibleNav.map((entry) => (
            isGroup(entry) ? (
              <NavGroup entry={entry} hash={hash} key={entry.group} />
            ) : (
              <StandaloneNavLink
                key={entry.href}
                item={entry.current && sectionInGroup ? { ...entry, current: false } : entry}
              />
            )
          ))}
        </nav>

        <form method="post" action="/admin/logout">
          <input
            type="hidden"
            name="_csrf_token"
            value={logoutToken}
          />
          <button className="button button-secondary" type="submit">
            Cerrar sesión
          </button>
        </form>
      </aside>

      <main className="workspace" aria-label="Área de trabajo" data-module={/^[a-z0-9-]+$/.test(activeId) ? activeId : 'inicio'}>
        {/* El CSS estático activa solo el módulo elegido: compatible con CSP sin estilos embebidos. */}
        {variant === 'tenant' && <AdminGlobalSearch />}
        {activeLabel && (variant === 'tenant' || activeId !== 'inicio') && (
          <nav className="module-breadcrumb" aria-label="Ruta">
            <a href={variant === 'platform' ? '/adminpl0n3r' : '/admin'}>Inicio</a>
            <span aria-hidden="true">/</span>
            <span>{activeGroup}</span>
            <span aria-hidden="true">/</span>
            <strong aria-current="page">{activeLabel}</strong>
          </nav>
        )}
        {designPreview && DesignReference ? (
          <Suspense fallback={<output>Cargando laboratorio de diseño…</output>}>
            <DesignReference />
          </Suspense>
        ) : (auditView ? <PlatformAuditPanel /> : children)}
      </main>
    </section>
  );
}
