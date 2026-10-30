import { StrictMode, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import gsap from 'gsap';
import { createRoot } from 'react-dom/client';

type Plan = {key:string;name:string;version:number;monthly_amount:number|null;annual_amount:number|null;quote_required:boolean;verticals:string[]};
type Vertical = {key:string;name:string};
type Catalog = {plans:Plan[];verticals:Vertical[]};
type Capability = {key:string;name:string;priority:number};
type AddOn = {key:string;name:string;monthly_amount:number|null;quote_required:boolean;selectable:boolean};
type Options = {plan:Plan & {currency:string};vertical:Vertical;limits:Record<string,number>;capabilities:Capability[];addons:AddOn[]};
type Quote = {cycle:string;quantities:Record<string,number>;addons:string[];base_amount:number|null;addon_amount:number|null;total_amount:number|null;proposal_required:boolean;valid_until:string};

const money=(value:number|null)=>value===null?'—':new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(value);

const motionOk=()=>typeof window!=='undefined'&&window.matchMedia('(prefers-reduced-motion: no-preference)').matches;

// El total es el único momento animado: solo los dígitos que cambian entran desde abajo con un leve desenfoque.
function Price({text}:{text:string}){
  const box=useRef<HTMLSpanElement>(null);
  const previous=useRef<string|null>(null);
  useLayoutEffect(()=>{
    const before=previous.current;
    previous.current=text;
    if(before===null||before===text||!box.current||!motionOk())return;
    const changed=Array.from(box.current.children).filter((el,i)=>before[i]!==text[i]);
    if(changed.length===0)return;
    const tween=gsap.fromTo(changed,{y:8,opacity:0,filter:'blur(2px)'},{y:0,opacity:1,filter:'blur(0px)',duration:.5,stagger:.07,ease:'back.out(1.45)',overwrite:true,clearProps:'transform,opacity,filter'});
    return()=>{tween.kill();};
  },[text]);
  return <span className="total" ref={box} aria-label={text}>{Array.from(text).map((ch,i)=><span aria-hidden="true" key={i}>{ch}</span>)}</span>;
}

function Configurator(){
  const [catalog,setCatalog]=useState<Catalog|null>(null);
  const [plan,setPlan]=useState('');
  const [vertical,setVertical]=useState('');
  const [options,setOptions]=useState<Options|null>(null);
  const [quantities,setQuantities]=useState<Record<string,number>>({});
  const [addons,setAddons]=useState<string[]>([]);
  const [cycle,setCycle]=useState<'monthly'|'annual'>('monthly');
  const [quote,setQuote]=useState<Quote|null>(null);
  const [loading,setLoading]=useState(true);
  const [error,setError]=useState('');

  useEffect(()=>{
    const controller=new AbortController();
    fetch('/api/public/configurator/catalog',{signal:controller.signal})
      .then(r=>r.ok?r.json():Promise.reject(new Error('No pudimos cargar el catálogo.')))
      .then((data:Catalog)=>{
        setCatalog(data);
        const first=data.plans[0];
        setPlan(first?.key??'');
        setVertical(first?.verticals[0]??data.verticals[0]?.key??'');
      })
      .catch(e=>{if(e.name!=='AbortError')setError(e.message);})
      .finally(()=>setLoading(false));
    return()=>controller.abort();
  },[]);

  useEffect(()=>{
    setQuote(null);
    setOptions(null);
    if(!plan||!vertical)return;
    const controller=new AbortController();
    setError('');
    const safePlan=catalog?.plans.find(item=>item.key===plan);
    const safeVertical=catalog?.verticals.find(item=>item.key===vertical);
    if(!safePlan||!safeVertical||!safePlan.verticals.includes(safeVertical.key)){
      setError('La selección no pertenece al catálogo vigente.');
      return;
    }
    const params=new URLSearchParams({plan:safePlan.key,vertical:safeVertical.key});
    fetch('/api/public/configurator/options?'+params.toString(),{signal:controller.signal})
      .then(r=>r.ok?r.json():Promise.reject(new Error('Esa combinación no está disponible.')))
      .then((data:Options)=>{
        setOptions(data);
        setQuantities(Object.fromEntries(Object.entries(data.limits).map(([key,value])=>[key,value])));
        setAddons([]);
      })
      .catch(e=>{if(e.name!=='AbortError'){setOptions(null);setError(e.message);}});
    return()=>controller.abort();
  },[catalog,plan,vertical]);

  const payload=useMemo(()=>options?{plan,vertical,cycle,quantities,addons}:null,[options,plan,vertical,cycle,quantities,addons]);

  useEffect(()=>{
    if(!payload)return;
    const controller=new AbortController();
    const timer=window.setTimeout(()=>{
      fetch('/api/public/configurator/quote',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload),
        signal:controller.signal,
      })
        .then(r=>r.ok?r.json():Promise.reject(new Error('No pudimos recalcular esta configuración.')))
        .then(data=>{setQuote(data.quote as Quote);setError('');})
        .catch(e=>{if(e.name!=='AbortError'){setQuote(null);setError(e.message);}});
    },250);
    return()=>{window.clearTimeout(timer);controller.abort();};
  },[payload]);

  const LIMIT_LABELS:Record<string,string>={companies:'Empresas',locations:'Sedes',users:'Usuarios'};
  const LIMIT_HELP:Record<string,string>={companies:'Razones sociales que vas a administrar',locations:'Sucursales, bodegas o puntos de venta',users:'Personas de tu equipo que van a entrar'};
  const activePlan=catalog?.plans.find(item=>item.key===plan);
  const verticals=catalog?.verticals.filter(item=>activePlan?.verticals.includes(item.key))??[];
  const setQty=(key:string,next:number)=>setQuantities(current=>({...current,[key]:Math.max(1,next)}));

  if(loading)return <output className="cfg-loading">Cargando configurador…</output>;
  if(error&&!catalog)return <p role="alert">{error}</p>;
  if(!catalog||catalog.plans.length===0)return <output>El catálogo no tiene opciones disponibles.</output>;

  const chosenAddons=options?.addons.filter(item=>addons.includes(item.key))??[];
  const yearlySaving=activePlan&&activePlan.monthly_amount!==null&&activePlan.annual_amount!==null&&activePlan.annual_amount<activePlan.monthly_amount*12
    ?activePlan.monthly_amount*12-activePlan.annual_amount:null;

  return <div className="configurator-layout">
    <form className="configurator-flow" onSubmit={e=>e.preventDefault()}>
      <fieldset>
        <legend>1. Elige tu plan</legend>
        <p className="step-help">Empieza por el plan. Puedes cambiarlo después y el precio se actualiza solo.</p>
        <div className="choice-grid plans">
          {catalog.plans.map(item=><label className="choice" key={item.key}>
            <input type="radio" name="plan" value={item.key} checked={plan===item.key} onChange={()=>{setPlan(item.key);setVertical(item.verticals[0]??'');}}/>
            <strong>{item.name}</strong>
            <span className="price">{item.quote_required?'Cotización personalizada':money(item.monthly_amount)}</span>
            {!item.quote_required&&<small>por mes</small>}
          </label>)}
        </div>
      </fieldset>

      <fieldset>
        <legend>2. Tipo de operación</legend>
        <p className="step-help">Así adaptamos los módulos y el lenguaje a tu sector.</p>
        <label><span className="sr-only">Tipo de operación</span>
          <select value={vertical} onChange={e=>{
            const next=verticals.find(item=>item.key===e.currentTarget.value);
            if(next)setVertical(next.key);
          }}>
            {verticals.map(item=><option key={item.key} value={item.key}>{item.name}</option>)}
          </select>
        </label>
      </fieldset>

      {options&&<fieldset>
        <legend>3. Escala</legend>
        <p className="step-help">Tu plan ya incluye una cantidad. Sube el número solo si necesitas más.</p>
        <div className="rows">
          {Object.entries(options.limits).map(([key,included])=>{
            const value=quantities[key]??included;
            const name=LIMIT_LABELS[key]??key;
            return <div className="row qty" key={key}>
              <div className="row-text">
                <span className="row-title">{name}</span>
                <span className="hint">{LIMIT_HELP[key]??''} · incluye {included}</span>
              </div>
              <div className="stepper">
                <button type="button" aria-label={`Menos ${name}`} disabled={value<=1} onClick={()=>setQty(key,value-1)}>−</button>
                <input aria-label={name} type="number" min={1} value={value} onChange={e=>setQty(key,Number(e.target.value)||1)}/>
                <button type="button" aria-label={`Más ${name}`} onClick={()=>setQty(key,value+1)}>+</button>
              </div>
            </div>;
          })}
        </div>
      </fieldset>}

      {options&&<fieldset>
        <legend>4. Qué incluye y qué puedes sumar</legend>
        <p className="step-help">Estas capacidades ya vienen con tu plan. Abre una para ver si la necesitas.</p>
        <div className="capability-list">
          {options.capabilities.map(item=><details key={item.key}><summary><svg className="tick" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round"/></svg>{item.name}<small> · ¿Necesito esto?</small></summary><p>Esta capacidad está incluida por el catálogo para tu plan y vertical.</p></details>)}
        </div>
        {options.addons.some(item=>item.selectable)&&<p className="step-help addon-intro">Complementos opcionales</p>}
        <div className="choice-grid">
          {options.addons.filter(item=>item.selectable).map(item=><label className="choice" key={item.key}>
            <input type="checkbox" checked={addons.includes(item.key)} onChange={e=>setAddons(current=>e.target.checked?[...current,item.key]:current.filter(key=>key!==item.key))}/>
            <strong>{item.name}</strong>
            <span className="price">{item.quote_required?'Requiere propuesta':'+ '+money(item.monthly_amount)}</span>
            {!item.quote_required&&<small>por mes</small>}
          </label>)}
        </div>
      </fieldset>}

      <fieldset>
        <legend>5. Cómo quieres pagar</legend>
        <div className="segmented" data-cycle={cycle} role="radiogroup" aria-label="Ciclo de pago">
          {(['monthly','annual'] as const).map(item=><label className="seg" key={item}>
            <input type="radio" name="cycle" value={item} checked={cycle===item} onChange={()=>setCycle(item)}/>
            <span>{item==='monthly'?'Mensual':'Anual'}</span>
          </label>)}
        </div>
        {yearlySaving!==null&&<p className="step-help">Pagando por año ahorras {money(yearlySaving)} frente a pagar 12 meses.</p>}
      </fieldset>
      {error&&<p role="alert" className="error-box">{error}</p>}
    </form>

    <section className="quote-summary" aria-live="polite" aria-label="Resumen de configuración">
      <span className="eyebrow">Tu configuración</span>
      <h2>{activePlan?.name??'Plan'}</h2>
      <p className="vert">{options?.vertical.name??'Selecciona una operación'}</p>
      {options&&<ul className="sum-list">
        {Object.entries(options.limits).map(([key,included])=><li key={key}><span>{LIMIT_LABELS[key]??key}</span><b>{quantities[key]??included}</b></li>)}
        {chosenAddons.map(item=><li key={item.key}><span>{item.name}</span><b>{item.quote_required?'propuesta':money(item.monthly_amount)}</b></li>)}
      </ul>}
      {quote?.proposal_required
        ? <><strong>Propuesta personalizada</strong><p>Esta combinación requiere validación comercial. No mostramos un precio inventado.</p></>
        : <><span className="total-label">Total estimado</span><Price text={money(quote?.total_amount??null)}/><span className="per">{cycle==='monthly'?'por mes':'por año'}</span></>}
      <p className="muted">El total se calcula en el servidor con el catálogo vigente.</p>
      <a className="mk-btn mk-btn-primary quote-cta" href="/#solicitar-demo">Solicitar esta configuración</a>
    </section>
  </div>;
}

const root=document.getElementById('condor-configurator-root');
if(root)createRoot(root).render(<StrictMode><Configurator/></StrictMode>);
