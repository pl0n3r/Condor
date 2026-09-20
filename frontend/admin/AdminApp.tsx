type AdminAppProps = {
  version: string;
};

export function AdminApp({ version }: AdminAppProps) {
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
      </aside>

      <div className="workspace">
        <span className="eyebrow">Administrador</span>
        <h1>Fundación de Condor</h1>
        <p className="muted">
          El primer vertical slice está construyendo la base multi-tenant del producto.
        </p>

        <div className="foundation-grid" aria-label="Estado del Slice 1">
          <article>
            <strong>Tenant</strong>
            <span>Base organizacional preparada</span>
          </article>
          <article>
            <strong>Sede principal</strong>
            <span>Creación predeterminada incluida</span>
          </article>
          <article>
            <strong>Acceso</strong>
            <span>Login seguro conectado al backend</span>
          </article>
        </div>
      </div>
    </section>
  );
}
