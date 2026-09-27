import { StrictMode, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

type Plan = {key:string;name:string;version:number;monthly_amount:number|null;annual_amount:number|null;quote_required:boolean;verticals:string[]};
type Vertical = {key:string;name:string};
type Catalog = {plans:Plan[];verticals:Vertical[]};
type Capability = {key:string;name:string;priority:number};
type AddOn = {key:string;name:string;monthly_amount:number|null;quote_required:boolean;selectable:boolean};
type Options = {plan:Plan & {currency:string};vertical:Vertical;limits:Record<string,number>;capabilities:Capability[];addons:AddOn[]};
type Quote = {cycle:string;quantities:Record<string,number>;addons:string[];base_amount:number|null;addon_amount:number|null;total_amount:number|null;proposal_required:boolean;valid_until:string};

const money=(value:number|null)=>value===null?'—':new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(value);

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

  const activePlan=catalog?.plans.find(item=>item.key===plan);
  const verticals=catalog?.verticals.filter(item=>activePlan?.verticals.includes(item.key))??[];

  if(loading)return <output>Cargando configurador…</output>;
  if(error&&!catalog)return <p role="alert">{error}</p>;
  if(!catalog||catalog.plans.length===0)return <output>El catálogo no tiene opciones disponibles.</output>;

  return <div className="configurator-layout">
    <form className="configurator-flow" onSubmit={e=>e.preventDefault()}>
      <fieldset>
        <legend>1. Elige tu plan</legend>
        <div className="choice-grid">
          {catalog.plans.map(item=><label className="choice" key={item.key}>
            <input type="radio" name="plan" value={item.key} checked={plan===item.key} onChange={()=>{setPlan(item.key);setVertical(item.verticals[0]??'');}}/>
            <strong>{item.name}</strong>
            <span>{item.quote_required?'Cotización personalizada':money(item.monthly_amount)+' / mes'}</span>
          </label>)}
        </div>
      </fieldset>

      <fieldset>
        <legend>2. Tipo de operación</legend>
        <label><span>Vertical</span>
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
        <div className="field-grid">
          {Object.entries(options.limits).map(([key,included])=><label key={key}>{key}
            <span className="hint">Incluye {included}</span>
            <input type="number" min={1} value={quantities[key]??included} onChange={e=>setQuantities(current=>({...current,[key]:Math.max(1,Number(e.target.value)||1)}))}/>
          </label>)}
        </div>
      </fieldset>}

      {options&&<fieldset>
        <legend>4. Capacidades y add-ons</legend>
        <div className="capability-list">
          {options.capabilities.map(item=><details key={item.key}><summary>{item.name} · ¿Necesito esto?</summary><p>Esta capacidad está incluida por el catálogo para tu plan y vertical.</p></details>)}
        </div>
        <div className="choice-grid">
          {options.addons.filter(item=>item.selectable).map(item=><label className="choice" key={item.key}>
            <input type="checkbox" checked={addons.includes(item.key)} onChange={e=>setAddons(current=>e.target.checked?[...current,item.key]:current.filter(key=>key!==item.key))}/>
            <strong>{item.name}</strong>
            <span>{item.quote_required?'Requiere propuesta':money(item.monthly_amount)+' / mes'}</span>
          </label>)}
        </div>
      </fieldset>}

      <fieldset>
        <legend>5. Ciclo</legend>
        <div className="choice-grid compact">
          {(['monthly','annual'] as const).map(item=><label className="choice" key={item}>
            <input type="radio" name="cycle" value={item} checked={cycle===item} onChange={()=>setCycle(item)}/>
            <strong>{item==='monthly'?'Mensual':'Anual'}</strong>
          </label>)}
        </div>
      </fieldset>
      {error&&<p role="alert" className="error-box">{error}</p>}
    </form>

    <section className="quote-summary" aria-live="polite" aria-label="Resumen de configuración">
      <span className="eyebrow">Tu configuración</span>
      <h2>{activePlan?.name??'Plan'}</h2>
      <p>{options?.vertical.name??'Selecciona una operación'}</p>
      {quote?.proposal_required
        ? <><strong>Propuesta personalizada</strong><p>Esta combinación requiere validación comercial. No mostramos un precio inventado.</p></>
        : <><span>Total estimado</span><strong className="total">{money(quote?.total_amount??null)}</strong><span>{cycle==='monthly'?'por mes':'por año'}</span></>}
      <p className="muted">El total se calcula en el servidor con el catálogo vigente.</p>
    </section>
  </div>;
}

const root=document.getElementById('condor-configurator-root');
if(root)createRoot(root).render(<StrictMode><Configurator/></StrictMode>);
