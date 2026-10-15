import { useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, Package, Plus, Warehouse } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import { StockCountsPanel } from './StockCountsPanel';
import type { Customer, Page } from '../types';

type Kind = 'receipt' | 'transfer' | 'issue';
type Section = 'balances' | 'movements' | 'lots' | 'counts' | 'items' | 'locations';
interface LotPosition { id:number; lot_id:number; item_id:number; item_sku:string; item_name:string; location_name:string; quantity:number; expires_on:string|null; expired:boolean; lot_number:string; unit_cost_cents:number; receipt_detail_id:number }
interface StockItem { id: number; sku: string; name: string; category: string; unit: string; active: boolean }
interface StockLocation {
  id: number; name: string; kind: 'provider' | 'technician' | 'customer';
  customer_id: number | null; customer_location_id: number | null;
  technician_user_id: number | null; active: boolean;
}
interface Balance { item_id: number; location_id: number; quantity: number; reserved_quantity: number; expired_quantity: number; available_quantity: number; item: StockItem; location: StockLocation }
interface Movement {
  id: number; request_id: string; kind: Kind; quantity: number; created_at: string; reason: string;
  source_before: number | null; source_after: number | null;
  destination_before: number | null; destination_after: number | null;
  item: StockItem; source: StockLocation | null; destination: StockLocation | null;
  actor: { id: number; name: string } | null;
  lot_allocations?: {lot_id:number;lot_number:string|null;quantity:number}[];
}
interface CustomerLocation { id: number; name: string; active: boolean }
interface Staff { id: number; name: string; active: boolean }
const labelKind: Record<Kind, string> = { receipt: 'Entrada', transfer: 'Transferência', issue: 'Baixa' };

function Pager({ value, onChange }: {
  value: Page<unknown> | undefined; onChange: (next: number) => void;
}) {
  const page = value?.meta?.current_page ?? value?.current_page ?? 1;
  const last = value?.meta?.last_page ?? value?.last_page ?? 1;
  return <div className="pagination"><span>Página {page} de {last} · {value?.meta?.total ?? value?.total ?? 0} registros</span>
    <div className="inline">
      <button className="button ghost" disabled={page <= 1} onClick={() => onChange(page - 1)}>Anterior</button>
      <button className="button ghost" disabled={page >= last} onClick={() => onChange(page + 1)}>Próxima</button>
    </div>
  </div>;
}
function Field({ label, children }: { label: string; children: ReactNode }) {
  return <div className="stock-field"><label>{label}{children}</label></div>;
}

function centsFromBrazilianValue(value: string): number | null {
  if (!value.trim()) return null;
  const normalized=value.trim().replace(',', '.');
  if (!/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/.test(normalized)) return null;
  const [whole, fractional='']=normalized.split('.');
  const cents=Number(whole)*100+Number(fractional.padEnd(2,'0'));
  return Number.isSafeInteger(cents)&&cents<=1000000000 ? cents : null;
}

function NewItem({ token, onCreated }: { token: string | null; onCreated: () => Promise<void> }) {
  const [sku, setSku] = useState(''); const [name, setName] = useState('');
  const [category, setCategory] = useState('toner'); const [unit, setUnit] = useState('un');
  const [brand,setBrand]=useState('');const [color,setColor]=useState('');
  const [cost,setCost]=useState('');const [price,setPrice]=useState('');
  const [yieldPages,setYieldPages]=useState('');
  const costCents=centsFromBrazilianValue(cost);
  const priceCents=centsFromBrazilianValue(price);
  const save = useMutation({
    mutationFn: () => api<unknown>('/stock/items', token, {
      method: 'POST', body: JSON.stringify({
      sku: sku.trim(), name: name.trim(), category: category.trim(), unit,
      brand:brand.trim()||null,color:color.trim()||null,
      cost_cents:costCents,price_cents:priceCents,
      yield_pages:yieldPages?Number(yieldPages):null,
    }),
    }),
    onSuccess: async () => { setSku(''); setName(''); await onCreated(); },
  });
  const submit = (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); save.mutate(); };
  return <form className="stock-form" onSubmit={submit}>
    <h3>Novo item de estoque</h3>
    <div className="stock-form-grid">
      <Field label="SKU *"><input required maxLength={80} value={sku} onChange={e => setSku(e.target.value)}/></Field>
      <Field label="Descrição *"><input required minLength={2} maxLength={180} value={name} onChange={e => setName(e.target.value)}/></Field>
      <Field label="Tipo / categoria *"><input required minLength={2} maxLength={80} value={category} onChange={e => setCategory(e.target.value)}/></Field>
      <Field label="Unidade *"><input required maxLength={16} value={unit} onChange={e => setUnit(e.target.value)}/></Field>
      <Field label="Marca"><input maxLength={120} value={brand} onChange={e=>setBrand(e.target.value)}/></Field>
      <Field label="Cor"><input maxLength={40} value={color} onChange={e=>setColor(e.target.value)}/></Field>
      <Field label="Custo em R$"><input inputMode="decimal" placeholder="68,50" value={cost} onChange={e=>setCost(e.target.value)}/></Field>
      <Field label="Preço em R$"><input inputMode="decimal" placeholder="159,90" value={price} onChange={e=>setPrice(e.target.value)}/></Field>
      <Field label="Rendimento (páginas)"><input type="number" min={1} max={100000000} step={1} value={yieldPages} onChange={e=>setYieldPages(e.target.value)}/></Field>
    </div>
    {(cost && costCents===null) || (price && priceCents===null) ?
      <p role="alert" className="notice error">Custo e preço devem ter até duas casas decimais.</p> : null}
    {save.error && <p role="alert" className="notice error">{readableError(save.error)}</p>}
    <button className="button primary" type="submit" disabled={
      save.isPending || Boolean(cost&&costCents===null) || Boolean(price&&priceCents===null)
    }><Plus size={17}/> Cadastrar item</button>
  </form>;
}

function NewLocation({ token, onCreated }: { token: string | null; onCreated: () => Promise<void> }) {
  const [name, setName] = useState('');
  const [kind, setKind] = useState<StockLocation['kind']>('provider');
  const [customerId, setCustomerId] = useState('');
  const [unitId, setUnitId] = useState('');
  const [technicianId, setTechnicianId] = useState('');
  const customers = useQuery({
    queryKey: ['stock-customer-options'],
    queryFn: () => api<Page<Customer>>('/customers' + queryString({active_only: true, per_page: 100}), token),
    enabled: kind === 'customer',
  });
  const units = useQuery({
    queryKey: ['stock-unit-options', customerId],
    queryFn: () => api<Page<CustomerLocation>>('/customers/' + customerId + '/locations' +
      queryString({active_only: true, per_page: 100}), token),
    enabled: kind === 'customer' && customerId !== '',
  });
  const staff = useQuery({
    queryKey: ['stock-warehouse-staff'],
    queryFn: () => api<Page<Staff>>('/stock/operators' + queryString({ per_page: 100 }), token),
    enabled: kind === 'technician',
  });
  const save = useMutation({
    mutationFn: () => api<unknown>('/stock/locations', token, {
      method: 'POST', body: JSON.stringify({
        name: name.trim(), kind,
        customer_id: kind === 'customer' ? Number(customerId) : null,
        customer_location_id: kind === 'customer' ? Number(unitId) : null,
        technician_user_id: kind === 'technician' ? Number(technicianId) : null,
      }),
    }),
    onSuccess: async () => { setName(''); await onCreated(); },
  });
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    save.mutate();
  }
  const optionsError = customers.error || units.error || staff.error;
  const loadingOptions = (kind === 'customer' && (customers.isPending || (customerId !== '' && units.isPending))) ||
    (kind === 'technician' && staff.isPending);
  return <form className="stock-form" onSubmit={submit}>
    <h3>Novo local de estoque</h3>
    <div className="stock-form-grid">
      <Field label="Nome *"><input required minLength={2} maxLength={180} value={name} onChange={e => setName(e.target.value)}/></Field>
      <Field label="Tipo *"><select value={kind} onChange={e => {
        setKind(e.target.value as StockLocation['kind']); setCustomerId(''); setUnitId(''); setTechnicianId('');
      }}>
        <option value="provider">Depósito da empresa</option><option value="technician">Técnico</option>
        <option value="customer">Unidade do cliente</option>
      </select></Field>
      {kind === 'customer' && <>
        <Field label="Cliente *"><select required value={customerId} onChange={e => {setCustomerId(e.target.value);setUnitId('');}}>
          <option value="">Selecionar cliente</option>
          {(customers.data?.data ?? []).map(c => <option value={c.id} key={c.id}>{c.name}</option>)}
        </select></Field>
        <Field label="Unidade *"><select required disabled={!customerId} value={unitId} onChange={e => setUnitId(e.target.value)}>
          <option value="">Selecionar unidade</option>
          {(units.data?.data ?? []).map(u => <option value={u.id} key={u.id}>{u.name}</option>)}
        </select></Field>
      </>}
      {kind === 'technician' && <Field label="Operador *"><select required value={technicianId} onChange={e => setTechnicianId(e.target.value)}>
        <option value="">Selecionar membro</option>
        {(staff.data?.data ?? []).filter(s => s.active).map(s => <option value={s.id} key={s.id}>{s.name}</option>)}
      </select></Field>}
    </div>
    {optionsError && <p className="notice error" role="alert">{readableError(optionsError)}</p>}
    {save.error && <p className="notice error" role="alert">{readableError(save.error)}</p>}
    <button className="button primary" disabled={save.isPending || loadingOptions || Boolean(optionsError)}
      type="submit"><Plus size={17}/> Cadastrar local</button>
  </form>;
}

function MoveStock({ token, onCreated }: {
  token: string | null; onCreated: () => Promise<void>;
}) {
  const [kind, setKind] = useState<Kind>('receipt');
  const [itemId, setItemId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [reason, setReason] = useState('');
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const items = useQuery({
    queryKey: ['stock-options-items'],
    queryFn: () => api<Page<StockItem>>('/stock/items' + queryString({per_page: 100}), token),
  });
  const locations = useQuery({
    queryKey: ['stock-options-locations'],
    queryFn: () => api<Page<StockLocation>>('/stock/locations' + queryString({per_page: 100}), token),
  });
  function changed<T>(setter: (value: T) => void, value: T): void {
    setter(value);
    setRequestId(crypto.randomUUID());
  }
  const save = useMutation({
    mutationFn: () => api<unknown>('/stock/movements', token, {
      method: 'POST',
      body: JSON.stringify({
        request_id: requestId, kind, item_id: Number(itemId),
        source_location_id: kind === 'receipt' ? null : Number(from),
        destination_location_id: kind === 'issue' ? null : Number(to),
        quantity, reason: reason.trim(),
      }),
    }),
    onSuccess: async () => {
      setReason(''); setRequestId(crypto.randomUUID());
      await onCreated();
    },
  });

  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); save.mutate(); }
  const optionsError = items.error || locations.error;
  const activeLocations = (locations.data?.data ?? []).filter(l => l.active);
  return <form className="stock-form" onSubmit={submit}>
    <h3>Registrar movimentação</h3>
    <p className="stock-hint">Cada operação exige justificativa. O mesmo identificador impede registrar a baixa duas vezes.</p>
    <div className="stock-form-grid">
      <Field label="Movimentação *"><select value={kind} onChange={e => changed(setKind, e.target.value as Kind)}>
        <option value="receipt">Entrada externa</option>
        <option value="transfer">Transferência entre locais</option>
        <option value="issue">Baixa / consumo</option>
      </select></Field>
      <Field label="Item *"><select required value={itemId} onChange={e => changed(setItemId, e.target.value)}>
        <option value="">Selecionar item</option>
        {(items.data?.data ?? []).filter(i=>i.active).map(i=><option key={i.id} value={i.id}>{i.sku} — {i.name}</option>)}
      </select></Field>
      {kind !== 'receipt' && <Field label="Origem *"><select required value={from} onChange={e => changed(setFrom, e.target.value)}>
        <option value="">Selecionar origem</option>
        {activeLocations.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
      </select></Field>}
      {kind !== 'issue' && <Field label="Destino *"><select required value={to} onChange={e => changed(setTo, e.target.value)}>
        <option value="">Selecionar destino</option>
        {activeLocations.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
      </select></Field>}
      <Field label="Quantidade *"><input required min={1} max={1000000} step={1} type="number" value={quantity}
        onChange={e => changed(setQuantity, Number(e.target.value))}/></Field>
      <Field label="Justificativa *"><input required minLength={5} maxLength={500} value={reason}
        onChange={e => changed(setReason, e.target.value)}/></Field>
    </div>
    {optionsError && <p className="notice error" role="alert">{readableError(optionsError)}</p>}
    {save.error && <p className="notice error" role="alert">{readableError(save.error)}</p>}
    <button className="button primary" disabled={
      save.isPending || items.isPending || locations.isPending || Boolean(optionsError) || !itemId ||
      (kind !== 'receipt' && !from) || (kind !== 'issue' && !to) ||
      (kind === 'transfer' && from === to)
    } type="submit"><ArrowRightLeft size={17}/> {save.isPending ? 'Registrando...' : 'Registrar operação'}</button>
  </form>;
}

export function InventoryPage({token, role, userId}: {
  token: string | null; role: string; userId: number;
}) {
  const qc = useQueryClient();
  const [section, setSection] = useState<Section>('balances');
  const [page, setPage] = useState(1);
  const [notice, setNotice] = useState('');
  const listings = {
    balances: '/stock/balances', movements: '/stock/movements', lots:'/stock/lots',
    counts: '/stock/counts', items: '/stock/items', locations: '/stock/locations',
  };
  const listing = useQuery({
    queryKey: ['stock-list', section, page],
    queryFn: async () => {
      const url = listings[section] + queryString({page,per_page:25});
      return api<Page<Balance | Movement | LotPosition | StockItem | StockLocation>>(url, token);
    },
    enabled: section !== 'counts',
  });
  async function refresh() {
    setNotice('Operação registrada e auditada com sucesso.');
    await qc.invalidateQueries({ queryKey: ['stock-list'] });
    await qc.invalidateQueries({ queryKey: ['stock-options'] });
  }

  function change(next: Section) { setSection(next);setPage(1);setNotice(''); }
  return <>
    <div className="page-heading"><div><span className="eyebrow">CONTROLE DE MATERIAIS</span>
      <h1>Estoque e movimentações</h1>
      <p>Entradas, transferências e baixas por posição, com saldos independentes e histórico auditável.</p>
    </div><Warehouse size={36} color="#397cf7"/></div>
    <nav className="stock-tabs" aria-label="Gestão do estoque">
      {(['balances','lots','movements','counts','items','locations'] as Section[]).map(value =>
        <button key={value} className={'organization-tab' + (section===value?' selected':'')} onClick={()=>change(value)}>
          {({balances:'Saldos',lots:'Lotes',movements:'Movimentações',counts:'Contagens',items:'Itens',locations:'Locais'} as Record<Section,string>)[value]}
        </button>)}
    </nav>
    {notice && <p role="status" className="notice success">{notice}</p>}
    {section === 'counts' && <StockCountsPanel token={token} role={role} userId={userId} />}
    {section !== 'counts' && <section className="data-card stock-listing">
      {listing.isPending ? <div className="loading">Carregando estoque...</div> :
        listing.error ? <p role="alert" className="notice error">{readableError(listing.error)}</p> :
        listing.data.data.length===0 ? <div className="empty-state"><Package size={30}/><p>Nenhum registro nesta categoria.</p></div> :
        <div className="table-wrap"><table><thead><tr>
          {section==='balances' ? <><th>Item</th><th>Local</th><th>Físico</th><th>Reservado</th><th>Vencido</th><th>Disponível</th></> :
           section==='lots' ? <><th>Item</th><th>Lote</th><th>Local</th><th>Saldo rastreado</th><th>Validade</th><th>Custo unitário</th></> :
           section==='movements' ? <><th>Item</th><th>Operação</th><th>Origem / destino</th><th>Quantidade</th><th>Rastreio</th><th>Responsável</th><th>Data</th></> :
           section==='items' ? <><th>SKU</th><th>Item</th><th>Tipo</th><th>Estado</th></> :
             <><th>Local</th><th>Tipo</th><th>Cliente</th><th>Estado</th></>}</tr></thead>
          <tbody>{listing.data.data.map(record => {
            if (section==='balances') {
              const b=record as Balance;return <tr key={b.item_id + '-' + b.location_id}>
                <td><strong>{b.item.sku}</strong><small>{b.item.name}</small></td><td>{b.location.name}</td>
                <td><strong>{b.quantity.toLocaleString('pt-BR')} {b.item.unit}</strong></td>
                <td>{b.reserved_quantity.toLocaleString('pt-BR')} {b.item.unit}</td>
                <td>{b.expired_quantity.toLocaleString('pt-BR')} {b.item.unit}</td>
                <td><strong>{b.available_quantity.toLocaleString('pt-BR')} {b.item.unit}</strong></td></tr>;
            }
            if (section==='lots') {
              const lot=record as LotPosition;return <tr key={lot.id}>
                <td><strong>{lot.item_sku}</strong><small>{lot.item_name}</small></td>
                <td>{lot.lot_number}</td><td>{lot.location_name}</td>
                <td><strong>{lot.quantity.toLocaleString('pt-BR')}</strong></td>
                <td>{lot.expires_on ?? 'Indeterminada'} {lot.expired ? '· VENCIDO' : ''}</td>
                <td>{new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(lot.unit_cost_cents/100)}</td>
              </tr>;
            }
            if (section==='movements') {
              const m=record as Movement;return <tr key={m.id}>
                <td><strong>{m.item.sku}</strong><small>{m.item.name}</small></td>
                <td>{labelKind[m.kind]}</td><td>{m.source?.name ?? 'Externo'} → {m.destination?.name ?? 'Consumo'}</td>
                <td><strong>{m.quantity}</strong></td>
                <td>{m.lot_allocations?.length ? m.lot_allocations.map(a => a.lot_number + ' ('+a.quantity+')').join(', ') : 'Sem lote conhecido'}</td>
                <td>{m.actor?.name ?? '—'}<small title={m.reason}>{m.reason}</small></td>
                <td>{new Date(m.created_at).toLocaleString('pt-BR')}</td></tr>;
            }
            if (section==='items') {
              const i=record as StockItem;return <tr key={i.id}>
                <td><strong>{i.sku}</strong></td><td>{i.name}</td><td>{i.category}</td>
                <td>{i.active?'Ativo':'Inativo'}</td></tr>;
            }
            const l=record as StockLocation;return <tr key={l.id}>
              <td><strong>{l.name}</strong></td><td>{({provider:'Provedor',technician:'Técnico',customer:'Cliente'} as Record<string,string>)[l.kind]}</td>
              <td>{l.customer_id??'—'}</td><td>{l.active?'Ativo':'Inativo'}</td></tr>;
          })}</tbody></table></div>}
      {!listing.isPending && !listing.error && <Pager value={listing.data} onChange={setPage}/>}
    </section>}
    {section !== 'counts' && <div className="stock-forms">
      <MoveStock token={token} onCreated={refresh}/>
      <NewItem token={token} onCreated={refresh}/>
      <NewLocation token={token} onCreated={refresh}/>
    </div>}
    <p className="stock-footnote">O saldo por lote rastreia somente entradas documentadas após esta migração. Estoque antigo permanece sem lote conhecido. Movimentações usam FEFO entre lotes válidos; perdas em lotes rastreados exigem contagem por lote.</p>
  </>;
}
