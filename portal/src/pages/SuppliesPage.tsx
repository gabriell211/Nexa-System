import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PackagePlus, Plus, ShieldCheck } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page } from '../types';

interface Supplier { id:number; code:string; name:string; email:string|null; active:boolean }
interface Item { id:number; sku:string; name:string; active:boolean }
interface Place { id:number; name:string; active:boolean }
interface Compatibility { id:number; printer_manufacturer:string; printer_model:string }
interface Receipt {
  id:number; movement_id:number; invoice_reference:string; lot_number:string;
  unit_cost_cents:number; expires_on:string|null;
  supplier:{id:number;name:string;code:string};
  movement:{id:number;quantity:number;item:{id:number;sku:string;name:string}|null};
}

function parseMoney(value:string):number|null {
  const normalized=value.trim().replace(',', '.');
  if (!/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/.test(normalized)) return null;
  const [whole,frac=''] = normalized.split('.');
  const cents=Number(whole)*100+Number(frac.padEnd(2,'0'));
  return Number.isSafeInteger(cents)&&cents<=1000000000?cents:null;
}
function formatMoney(cents:number):string {
  return new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(cents/100);
}

function NewSupplier({token,onSaved}:{token:string|null;onSaved:()=>Promise<void>}) {
  const [code,setCode]=useState('');const [name,setName]=useState('');
  const [email,setEmail]=useState('');
  const save=useMutation({
    mutationFn:()=>api<unknown>('/stock/suppliers',token,{
      method:'POST',body:JSON.stringify({
        code:code.trim(),name:name.trim(),email:email.trim()||null,
      }),
    }),
    onSuccess:async()=>{setCode('');setName('');setEmail('');await onSaved();},
  });
  function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();save.mutate();}
  return <form className="stock-form" onSubmit={submit}>
    <h3>Cadastrar fornecedor</h3>
    <div className="stock-form-grid">
      <div className="stock-field"><label>Código *<input required maxLength={60} value={code} onChange={e=>setCode(e.target.value)}/></label></div>
      <div className="stock-field"><label>Razão social / nome *<input required minLength={2} maxLength={180} value={name} onChange={e=>setName(e.target.value)}/></label></div>
      <div className="stock-field"><label>E-mail<input type="email" maxLength={180} value={email} onChange={e=>setEmail(e.target.value)}/></label></div>
    </div>
    {save.error&&<p className="notice error" role="alert">{readableError(save.error)}</p>}
    <button className="button primary" disabled={save.isPending} type="submit"><Plus size={16}/> Cadastrar fornecedor</button>
  </form>;
}

function NewReceipt({token,onSaved}:{token:string|null;onSaved:()=>Promise<void>}) {
  const [itemId,setItemId]=useState('');
  const [supplierId,setSupplierId]=useState('');
  const [locationId,setLocationId]=useState('');
  const [quantity,setQuantity]=useState(1);
  const [invoice,setInvoice]=useState('');const [lot,setLot]=useState('');
  const [expires,setExpires]=useState('');const [price,setPrice]=useState('');
  const [reason,setReason]=useState('');
  const [id,setId]=useState(()=>crypto.randomUUID());

  const suppliers=useQuery({queryKey:['supply-supplier-options'],
    queryFn:()=>api<Page<Supplier>>('/stock/suppliers'+queryString({per_page:100}),token)});
  const items=useQuery({queryKey:['supply-item-options'],
    queryFn:()=>api<Page<Item>>('/stock/items'+queryString({per_page:100}),token)});
  const locations=useQuery({queryKey:['supply-location-options'],
    queryFn:()=>api<Page<Place>>('/stock/locations'+queryString({per_page:100}),token)});
  const cents=parseMoney(price);
  const save=useMutation({
    mutationFn:()=>api<unknown>('/stock/receipts',token,{
      method:'POST',
      body:JSON.stringify({
        request_id:id,supplier_id:Number(supplierId),
        item_id:Number(itemId),destination_location_id:Number(locationId),
        quantity,invoice_reference:invoice.trim(),lot_number:lot.trim(),
        expires_on:expires||null,unit_cost_cents:cents,reason:reason.trim(),
      }),
    }),
    onSuccess:async()=>{
      setInvoice('');setLot('');setExpires('');setReason('');setId(crypto.randomUUID());
      await onSaved();
    },
  });
  function update<T>(set:(v:T)=>void,value:T) {set(value);setId(crypto.randomUUID());}
  function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();if(cents!==null)save.mutate();}
  const optionError=suppliers.error||items.error||locations.error;
  return <form className="stock-form" onSubmit={submit}>
    <h3>Recebimento fiscal e lote</h3>
    <p className="stock-hint">O movimento de entrada e seus dados fiscais são registrados em uma única transação. Valores informados em reais são convertidos para centavos sem ponto flutuante.</p>
    <div className="stock-form-grid">
      <div className="stock-field"><label>Fornecedor *<select required value={supplierId} onChange={e=>update(setSupplierId,e.target.value)}>
        <option value="">Selecione</option>{(suppliers.data?.data??[]).filter(x=>x.active).map(s=><option key={s.id} value={s.id}>{s.name}</option>)}
      </select></label></div>
      <div className="stock-field"><label>Material *<select required value={itemId} onChange={e=>update(setItemId,e.target.value)}>
        <option value="">Selecione</option>{(items.data?.data??[]).filter(x=>x.active).map(s=><option key={s.id} value={s.id}>{s.sku} — {s.name}</option>)}
      </select></label></div>
      <div className="stock-field"><label>Depósito destino *<select required value={locationId} onChange={e=>update(setLocationId,e.target.value)}>
        <option value="">Selecione</option>{(locations.data?.data??[]).filter(x=>x.active).map(s=><option key={s.id} value={s.id}>{s.name}</option>)}
      </select></label></div>
      <div className="stock-field"><label>Quantidade *<input required type="number" min={1} max={1000000} step={1} value={quantity} onChange={e=>update(setQuantity,Number(e.target.value))}/></label></div>
      <div className="stock-field"><label>Nota / documento *<input required maxLength={100} value={invoice} onChange={e=>update(setInvoice,e.target.value)}/></label></div>
      <div className="stock-field"><label>Lote *<input required maxLength={100} value={lot} onChange={e=>update(setLot,e.target.value)}/></label></div>
      <div className="stock-field"><label>Validade (opcional)<input type="date" value={expires} onChange={e=>update(setExpires,e.target.value)}/></label></div>
      <div className="stock-field"><label>Custo unitário em R$ *<input required inputMode="decimal" placeholder="68,75" value={price} onChange={e=>update(setPrice,e.target.value)}/></label></div>
      <div className="stock-field"><label>Justificativa *<input required minLength={5} maxLength={500} value={reason} onChange={e=>update(setReason,e.target.value)}/></label></div>
    </div>
    {price&&cents===null&&<p className="notice error" role="alert">Informe um custo em reais com até duas casas decimais.</p>}
    {optionError&&<p role="alert" className="notice error">{readableError(optionError)}</p>}
    {save.error&&<p role="alert" className="notice error">{readableError(save.error)}</p>}
    <button className="button primary" type="submit" disabled={
      save.isPending||suppliers.isPending||items.isPending||locations.isPending||Boolean(optionError)
      ||!supplierId||!itemId||!locationId||cents===null
    }><PackagePlus size={16}/> Registrar recebimento</button>
  </form>;
}

function CompatibilityEditor({token,canRemove}:{token:string|null;canRemove:boolean}) {
  const qc=useQueryClient();
  const [itemId,setItemId]=useState('');
  const [maker,setMaker]=useState('');const [model,setModel]=useState('');
  const items=useQuery({queryKey:['supply-item-options'],
    queryFn:()=>api<Page<Item>>('/stock/items'+queryString({per_page:100}),token)});
  const matches=useQuery({
    queryKey:['supply-compatibilities',itemId],
    queryFn:()=>api<{data:Compatibility[]}>('/stock/items/'+itemId+'/compatibilities',token),
    enabled:itemId!=='',
  });
  const create=useMutation({
    mutationFn:()=>api<unknown>('/stock/items/'+itemId+'/compatibilities',token,{
      method:'POST',body:JSON.stringify({printer_manufacturer:maker,printer_model:model}),
    }),
    onSuccess:async()=>{
      setMaker('');setModel('');
      await qc.invalidateQueries({queryKey:['supply-compatibilities',itemId]});
    },
  });
  const remove=useMutation({
    mutationFn:(id:number)=>api<void>('/stock/items/'+itemId+'/compatibilities/'+id,token,{method:'DELETE'}),
    onSuccess:async()=>qc.invalidateQueries({queryKey:['supply-compatibilities',itemId]}).then(()=>undefined),
  });
  function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();create.mutate();}
  return <section className="stock-form">
    <h3>Compatibilidade de modelos</h3>
    <p className="stock-hint">Modelos não cadastrados são classificados como desconhecidos, não como incompatíveis.</p>
    <div className="stock-field"><label>Material<select value={itemId} onChange={e=>setItemId(e.target.value)}>
      <option value="">Selecione</option>{(items.data?.data??[]).map(i=><option key={i.id} value={i.id}>{i.sku} — {i.name}</option>)}
    </select></label></div>
    {itemId&&<>
      <form className="stock-form-grid" onSubmit={submit}>
        <div className="stock-field"><label>Fabricante *<input required minLength={2} maxLength={120} value={maker} onChange={e=>setMaker(e.target.value)}/></label></div>
        <div className="stock-field"><label>Modelo *<input required minLength={2} maxLength={160} value={model} onChange={e=>setModel(e.target.value)}/></label></div>
        <button className="button primary" type="submit" disabled={create.isPending}><Plus size={16}/> Adicionar compatibilidade</button>
      </form>
      {create.error&&<p className="notice error" role="alert">{readableError(create.error)}</p>}
      {remove.error&&<p className="notice error" role="alert">{readableError(remove.error)}</p>}
      {matches.isPending?<p>Carregando compatibilidades...</p>:matches.error?
        <p className="notice error" role="alert">{readableError(matches.error)}</p>:(
          matches.data.data.length===0?<p>Nenhum modelo confirmado.</p>:
          <div className="table-wrap"><table><thead><tr><th>Fabricante</th><th>Modelo</th><th>Ação</th></tr></thead>
            <tbody>{matches.data.data.map(x=><tr key={x.id}>
              <td>{x.printer_manufacturer}</td><td>{x.printer_model}</td>
              <td>{canRemove ? <button className="button ghost" disabled={remove.isPending}
                onClick={()=>remove.mutate(x.id)}><ShieldCheck size={15}/> Retirar vínculo</button> : 'Somente supervisão'}</td>
            </tr>)}</tbody></table></div>
      )}
    </>}
  </section>;
}

export function SuppliesPage({token,canRemove}:{token:string|null;canRemove:boolean}) {
  const qc=useQueryClient();
  const [page,setPage]=useState(1);
  const [notice,setNotice]=useState('');
  const listing=useQuery({
    queryKey:['supply-receipts',page],
    queryFn:()=>api<Page<Receipt>>('/stock/receipts'+queryString({page,per_page:25}),token),
  });
  async function refresh() {
    setNotice('Cadastro atualizado e auditado.');
    await Promise.all([
      qc.invalidateQueries({queryKey:['supply-receipts']}),
      qc.invalidateQueries({queryKey:['supply-supplier-options']}),
      qc.invalidateQueries({queryKey:['stock-list']}),
    ]);
  }
  const current=listing.data?.meta?.current_page??listing.data?.current_page??1;
  const last=listing.data?.meta?.last_page??listing.data?.last_page??1;
  return <div>
    <div className="page-heading"><div><span className="eyebrow">CATÁLOGO E ORIGEM DE MATERIAIS</span>
      <h1>Fornecedores e suprimentos</h1>
      <p>Notas, lotes, custos inteiros em centavos e compatibilidade confirmada por modelo.</p>
    </div></div>
    {notice&&<p className="notice success" role="status">{notice}</p>}
    <section className="data-card">
      <h3>Recebimentos documentados</h3>
      {listing.isPending?<div className="loading">Carregando...</div>:listing.error?
        <p className="notice error" role="alert">{readableError(listing.error)}</p>:
        listing.data.data.length===0?<div className="empty-state">Nenhum recebimento documentado.</div>:
        <div className="table-wrap"><table><thead><tr>
          <th>Material</th><th>Fornecedor</th><th>Nota</th><th>Lote</th><th>Validade</th><th>Quantidade</th><th>Custo unitário</th>
        </tr></thead><tbody>{listing.data.data.map(x=><tr key={x.id}>
          <td>{x.movement.item?.sku??'—'}</td><td>{x.supplier.name}</td>
          <td>{x.invoice_reference}</td><td>{x.lot_number}</td><td>{x.expires_on??'—'}</td>
          <td>{x.movement.quantity}</td><td>{formatMoney(x.unit_cost_cents)}</td>
        </tr>)}</tbody></table></div>}
      {listing.data&&<div className="pagination"><span>Página {current} de {last}</span>
        <div className="inline"><button className="button ghost" disabled={current<=1}
          onClick={()=>setPage(current-1)}>Anterior</button>
          <button className="button ghost" disabled={current>=last}
          onClick={()=>setPage(current+1)}>Próxima</button>
        </div></div>}
    </section>
    <div className="stock-forms">
      <NewReceipt token={token} onSaved={refresh}/>
      <NewSupplier token={token} onSaved={refresh}/>
      <CompatibilityEditor token={token} canRemove={canRemove}/>
    </div>
    <p className="stock-footnote">Lotes são registrados na entrada, mas ainda não rastreados nas baixas, transferências ou FIFO/FEFO. Não utilizar estes registros como rastreabilidade por unidade física até concluir o módulo de consumo por lote.</p>
  </div>;
}
