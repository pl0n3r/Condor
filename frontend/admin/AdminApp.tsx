import { useEffect, useState } from 'react';

type AdminAppProps = {
  version: string;
  logoutToken: string;
};

type TenantContextResponse = {
  tenant: {
    id: string;
    name: string;
    slug: string;
  };
  version: string;
};

type ContextState =
  | { status: 'loading' }
  | { status: 'ready'; data: TenantContextResponse }
  | { status: 'error' };

export function AdminApp({ version, logoutToken }: AdminAppProps) {
  const [context, setContext] = useState<ContextState>({ status: 'loading' });

  useEffect(() => {
    const controller = new AbortController();

    async function loadContext() {
      try {
        const response = await fetch('/api/v1/context', {
          credentials: 'same-origin',
          signal: controller.signal,
          headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
          throw new Error('No fue posible cargar el contexto');
        }

        const data = await response.json() as TenantContextResponse;
        setContext({ status: 'ready', data });
      } catch (error) {
        if (!controller.signal.aborted) {
          setContext({ status: 'error' });
        }
      }
    }

    void loadContext();

    return () => controller.abort();
  }, []);

  return (
    <section className="admin-shell" aria-label="Administrador de Condor">
      <aside className="sidebar">
        <div className="brand-block">
          <strong>Condor App</strong>
          <span>V {version}</span>
        </div>

        <nav aria-label="Navegación principal">
          <a href="/admin" aria-current="page">
            Inicio
          </a>
        </nav>

        <form method="post" action="/admin/logout">
          <input type="hidden" name="_csrf_token" value={logoutToken} />
          <button className="button button-secondary" type="submit">
            Cerrar sesión
          </button>
        </form>
      </aside>

      <div className="workspace">
        <span className="eyebrow">Administrador</span>
        {context.status === 'loading' && (
          <>
            <h1>Cargando empresa…</h1>
            <p className="muted" role="status">Preparando tu espacio de trabajo.</p>
          </>
        )}

        {context.status === 'error' && (
          <div className="alert alert-error" role="alert">
            No pudimos cargar el contexto de tu empresa. Recarga la página para intentarlo de nuevo.
          </div>
        )}

        {context.status === 'ready' && (
          <>
            <h1>{context.data.tenant.name}</h1>
            <p className="muted">
              Contexto activo: <strong>{context.data.tenant.slug}</strong>
            </p>

            <div className="foundation-grid" aria-label="Estado del Slice 1">
              <article>
                <strong>Tenant</strong>
                <span>Aislamiento y contexto activos</span>
              </article>
              <article>
                <strong>Sede principal</strong>
                <span>Creación predeterminada incluida</span>
              </article>
              <article>
                <strong>Acceso</strong>
                <span>Login y API comparten las mismas reglas del backend</span>
              </article>
            </div>
          </>
        )}
      </div>
    </section>
  );
}
