# Condor · propuesta de marca «Flightline»

> Estado: propuesta técnica y visual. No es identidad final aprobada.
>
> La aprobación explícita del owner es obligatoria antes de crear o instalar assets, cambiar tokens vivos o aplicar esta candidata a cualquier superficie del producto.
>
> Base técnica de validación: Condor V0.1.194. El patch es únicamente identidad de release; no aplica la marca en runtime.

## 1. Punto de partida

Condor ya comunica una idea fuerte y útil: «Tu empresa, conectada.» La marca debe reforzar esa promesa sin competir con el producto. El sistema actual es sobrio, claro, modular y corporativo; la propuesta conserva esos atributos y evita convertir la interfaz en una pieza publicitaria.

La dirección se resume en visión elevada + conexión operativa. El cóndor aporta altura, amplitud de visión y dominio del territorio. La plataforma aporta conexión entre inventario, ventas, producción, compras, e-commerce y gestión. La marca une ambos conceptos sin dibujar un ave literal ni un diagrama de nodos.

## 2. Concepto de logo: Flightline

### Símbolo

La candidata usa un trazo ascendente que puede leerse de dos maneras:

1. una ala de cóndor reducida a geometría esencial;
2. una C abierta cuya trayectoria conecta dos extremos.

Los extremos pueden resolverse como cortes o nodos geométricos muy discretos. No deben parecer iconografía de red, Wi‑Fi, blockchain ni dashboard. El símbolo comunica dirección y conexión; la aplicación funcional sigue viviendo en la UI.

### Wordmark

- Texto: CONDOR.
- Construcción propuesta: logotipo dibujado a medida en mayúsculas, geométrico y sobrio.
- Ritmo: tracking amplio y respirado, coherente con la wordmark textual actual.
- El wordmark final no debe depender de una fuente de interfaz.
- El símbolo se ubica antes del wordmark en la firma primaria.
- El wordmark puede utilizarse solo cuando el espacio sea horizontalmente limitado.
- El símbolo aislado queda reservado para favicon/app icon después de aprobación.

### Variantes previstas

- primary-horizontal: símbolo + CONDOR.
- wordmark-only.
- symbol-only.
- monochrome-positive: una tinta oscura.
- monochrome-negative: una tinta clara.

No se propone variante apilada como default: añade complejidad sin resolver una necesidad actual.

### Clearspace y tamaños mínimos

Definimos x = 1/4 de la altura de la O del wordmark. Debe conservarse al menos 1x libre en los cuatro lados.

| Uso | Mínimo |
| --- | ---: |
| Firma primaria digital | 96 px de ancho |
| Símbolo digital | 24 × 24 px |
| Firma primaria impresa | 24 mm de ancho |

### Usos prohibidos

No deformar, condensar, rotar, aplicar sombras/bevels/gradientes, inventar colores, reducir el clearspace ni usar el símbolo como icono de estado funcional. Un error, un success o una alerta deben seguir usando semántica propia.

## 3. Paleta candidata

| Token candidato | Hex | Rol |
| --- | --- | --- |
| Condor Cobalt | #2447E5 | marca primaria, CTA de marca |
| Cobalt Deep | #1D3AB8 | marca oscura |
| Condor Night | #0D1830 | fondos premium, firma negativa |
| Condor Cloud | #F5F6F2 | fondo cálido |
| Condor Paper | #FFFFFF | superficies |
| Condor Ink | #111412 | texto principal |
| Condor Slate | #5E665F | texto secundario |
| Success | #2F6B3B | estado positivo, no marca |
| Danger | #B42318 | error/peligro, no marca |
| Focus | #2257F5 | foco accesible, funcional |

El cobalt mantiene continuidad con el azul vigente de Condor, pero baja el carácter “SaaS genérico” al combinarlo con Night y neutros cálidos.

## 4. Accesibilidad

La especificación JSON declara pares de contraste obligatorios. El test de contrato calcula WCAG por luminancia relativa y exige una relación mínima 4.5:1 en todos los pares publicados para texto normal.

Pares principales: blanco sobre Condor Cobalt; blanco sobre Cobalt Deep; Ink sobre Cloud; Night sobre Cloud; Slate sobre Paper; y blanco sobre Success, Danger y Focus.

El color nunca es la única señal de estado. Focus, success y danger conservan su propia capa semántica.

## 5. Mapeo sobre los tokens existentes

Esta propuesta no sustituye los tokens semánticos. Si el owner la aprueba, un leaf posterior podrá traducir:

| Token semántico actual | Candidata |
| --- | --- |
| --brand | Condor Cobalt |
| --accent | Condor Night |
| --bg | Condor Cloud |
| --surface | Condor Paper |
| --text | Condor Ink |
| --muted | Condor Slate |
| --focus | Focus |
| --green | Success |
| --danger | Danger |

La regla de arquitectura visual es deliberada: los componentes consumen intención semántica, no nombres de colores de marca. Esto permite cambiar la identidad sin rehacer componentes ni alterar la semántica de estados.

## 6. Adopción progresiva

1. Propuesta: documento + especificación + contraste verificable.
2. Owner approval: decisión explícita; un merge técnico no cuenta como aprobación.
3. Assets finales: crear logo/wordmark/símbolo reales en un leaf separado.
4. Superficies públicas: adoptar tokens/assets con regresión visual, responsive y accesibilidad.
5. Backoffice: adopción progresiva, manteniendo estados funcionales y densidad operativa.

Cada paso es reversible y puede detenerse sin bloquear el producto.

## 7. Qué no cambia con este PR

No se modifica public/app.css, Twig, React, CMS themes, favicon, assets públicos ni Hostinger. El único cambio fuera de documentación/tests es el bump técnico de `config/version.php` a V0.1.194 exigido por la identidad de release single-use; no implica adopción de marca. La Home debe seguir mostrando exactamente el sistema vigente hasta una aprobación posterior.

## 8. Decisión pendiente

El owner debe decidir si Flightline se adopta, se itera o se descarta. Hasta entonces:

- proposal_only = true;
- owner_approval_required = true;
- runtime_applied = false;
- final_identity = false.

La propuesta puede quedar validada en código sin convertirse en marca final. La validación técnica de esta propuesta y la aprobación visual son gates distintos.
