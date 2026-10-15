import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, Pencil, Trash2 } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import type { Page } from '../types';

interface ItemOption { id: number; sku: string; name: string; unit: string; active: boolean }
interface LocationOption { id: number; name: string; active: boolean }
type NeedStatus = 'critical' | 'safety' | 'low' | 'ok' | 'inactive';
type NeedFilter = 'needs' | 'all' | NeedStatus;
interface ReorderNeed {
  id: number;
  item_id: number;
  location_id: number;
  item: ItemOption;
  location: LocationOption;
  physical_quantity: number;
  reserved_quantity: number;
  expired_quantity: number;
  available_quantity: number;
  critical_quantity: number;
  safety_quantity: number;
  minimum_quantity: number;
  target_quantity: number;
  suggested_quantity: number;
  status: NeedStatus;
}
type Limits = Pick<ReorderNeed,
  'critical_quantity' | 'safety_quantity' | 'minimum_quantity' | 'target_quantity'>;
type LimitKey = keyof Limits;

const limitFields: { key: LimitKey; label: string }[] = [
  { key: 'critical_quantity', label: 'Crítico' },
  { key: 'safety_quantity', label: 'Segurança' },
  { key: 'minimum_quantity', label: 'Mínimo' },
  { key: 'target_quantity', label: 'Alvo' },
];
const maxQuantity = 1_000_000_000;
const emptyLimits: Record<LimitKey, string> = {
  critical_quantity: '', safety_quantity: '', minimum_quantity: '', target_quantity: '',
};
const statusLabel: Record<NeedStatus, string> = {
  critical: 'Crítico', safety: 'Faixa de segurança', low: 'Abaixo do mínimo',
  ok: 'Adequado', inactive: 'Inativa',
};

function quantity(value: number, unit: string): string {
  return `${value.toLocaleString('pt-BR')} ${unit}`;
}

function validLimits(values: Record<LimitKey, string>): Limits | null {
  const parsed = {} as Limits;
  for (const { key } of limitFields) {
    if (!/^(0|[1-9][0-9]*)$/.test(values[key])) return null;
    const number = Number(values[key]);
    if (!Number.isSafeInteger(number) || number > maxQuantity) return null;
    parsed[key] = number;
  }
  if (parsed.critical_quantity > parsed.safety_quantity ||
      parsed.safety_quantity > parsed.minimum_quantity ||
      parsed.minimum_quantity > parsed.target_quantity) return null;
  return parsed;
}

export function ReorderNeedsPanel({ token, role }: { token: string | null; role: string }) {
  const qc = useQueryClient();
  const canEdit = ['owner', 'admin', 'manager', 'warehouse'].includes(role);
  const [filter, setFilter] = useState<NeedFilter>('needs');
  const [page, setPage] = useState(1);
  const [itemId, setItemId] = useState('');
  const [locationId, setLocationId] = useState('');
  const [itemOption, setItemOption] = useState<ItemOption | null>(null);
  const [locationOption, setLocationOption] = useState<LocationOption | null>(null);
  const [existing, setExisting] = useState(false);
  const [limits, setLimits] = useState<Record<LimitKey, string>>({ ...emptyLimits });
  const [notice, setNotice] = useState('');

  const listing = useQuery({
    queryKey: ['stock-reorder-needs', filter, page],
    queryFn: () => api<Page<ReorderNeed>>('/stock/reorder-needs' + queryString({
      page, per_page: 25, needs_only: filter === 'needs',
      ...(['critical', 'safety', 'low', 'ok', 'inactive'].includes(filter) ? { status: filter } : {}),
    }), token),
  });

  const save = useMutation({
    mutationFn: (values: Limits) => api<unknown>(
      `/stock/reorder-policies/${itemId}/${locationId}`, token,
      { method: 'PUT', body: JSON.stringify(values) },
    ),
    onSuccess: async () => {
      setExisting(true);
      setFilter('all'); setPage(1);
      setNotice('Política de reposição salva.');
      await qc.invalidateQueries({ queryKey: ['stock-reorder-needs'] });
    },
  });
  const remove = useMutation({
    mutationFn: () => api<void>(`/stock/reorder-policies/${itemId}/${locationId}`, token,
      { method: 'DELETE' }),
    onSuccess: async () => {
      clearEditor();
      setPage(1);
      setNotice('Política de reposição removida.');
      await qc.invalidateQueries({ queryKey: ['stock-reorder-needs'] });
    },
  });

  function clearEditor(): void {
    setItemId(''); setLocationId(''); setItemOption(null); setLocationOption(null);
    setExisting(false); setLimits({ ...emptyLimits });
    save.reset(); remove.reset();
  }

  function edit(need: ReorderNeed): void {
    setItemId(String(need.item_id)); setLocationId(String(need.location_id));
    setItemOption(need.item); setLocationOption(need.location); setExisting(true);
    setLimits(Object.fromEntries(limitFields.map(({ key }) => [key, String(need[key])])) as Record<LimitKey, string>);
    setNotice(''); save.reset(); remove.reset();
    document.getElementById('reorder-policy-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    const values = validLimits(limits);
    if (canEdit && itemId && locationId && values && !save.isPending) save.mutate(values);
  }

  function removePolicy(): void {
    if (!canEdit || !existing || !itemId || !locationId || remove.isPending) return;
    if (window.confirm('Remover a política de reposição deste item neste local?')) remove.mutate();
  }

  const parsed = validLimits(limits);
  const currentPage = listing.data?.meta?.current_page ?? listing.data?.current_page ?? page;
  const lastPage = listing.data?.meta?.last_page ?? listing.data?.last_page ?? 1;
  const total = listing.data?.meta?.total ?? listing.data?.total ?? 0;

  return <>
    <p className="stock-hint">Necessidades calculadas para políticas configuradas por item e local. A disponibilidade desconta reservas e lotes vencidos; a sugestão completa o saldo até o alvo. Nenhuma compra ou remessa é criada automaticamente.</p>
    {notice && <p className="notice success" role="status">{notice}</p>}
    <section className="data-card stock-listing" aria-label="Necessidades de reposição">
      <div className="stock-field reorder-filter"><label htmlFor="reorder-status-filter">Mostrar</label>
        <select id="reorder-status-filter" value={filter} onChange={event => {
          setFilter(event.target.value as NeedFilter); setPage(1);
        }}>
          <option value="needs">Necessitam reposição</option>
          <option value="all">Todas as políticas</option>
          <option value="critical">Crítico</option>
          <option value="safety">Faixa de segurança</option>
          <option value="low">Abaixo do mínimo</option>
          <option value="ok">Adequado</option>
          <option value="inactive">Inativas</option>
        </select>
      </div>
      {listing.isPending ? <p role="status">Carregando necessidades...</p> :
        listing.error ? <p role="alert" className="notice error">{readableError(listing.error)}</p> :
        listing.data.data.length === 0 ? <div className="empty-state"><ClipboardList size={30}/>
          <p>Nenhuma política neste filtro. Configure os limites por item e local para monitorar inclusive saldos zerados.</p>
        </div> : <div className="table-wrap"><table><thead><tr>
          <th>Item</th><th>Local</th><th>Físico</th><th>Reservado</th><th>Vencido</th>
          <th>Disponível</th><th>Crítico / segurança / mínimo / alvo</th><th>Sugestão</th>
          <th>Estado</th>{canEdit && <th>Ação</th>}
        </tr></thead><tbody>{listing.data.data.map(need => <tr key={`${need.item_id}-${need.location_id}`}>
          <td><strong>{need.item.sku}</strong><small>{need.item.name}</small></td>
          <td>{need.location.name}</td>
          <td>{quantity(need.physical_quantity, need.item.unit)}</td>
          <td>{quantity(need.reserved_quantity, need.item.unit)}</td>
          <td>{quantity(need.expired_quantity, need.item.unit)}</td>
          <td><strong>{quantity(need.available_quantity, need.item.unit)}</strong></td>
          <td>{[need.critical_quantity, need.safety_quantity, need.minimum_quantity,
            need.target_quantity].map(value => value.toLocaleString('pt-BR')).join(' / ')}</td>
          <td><strong>{quantity(need.suggested_quantity, need.item.unit)}</strong></td>
          <td>{statusLabel[need.status]}</td>
          {canEdit && <td><button type="button" className="button ghost" onClick={() => edit(need)}>
            <Pencil size={15}/> Editar</button></td>}
        </tr>)}</tbody></table></div>}
      {!listing.isPending && !listing.error && <div className="pagination">
        <span>Página {currentPage} de {lastPage} · {total} políticas</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={currentPage <= 1}
            onClick={() => setPage(currentPage - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={currentPage >= lastPage}
            onClick={() => setPage(currentPage + 1)}>Próxima</button>
        </div>
      </div>}
    </section>
    {canEdit && <form id="reorder-policy-editor" className="stock-form" onSubmit={submit}>
      <h3>{existing ? 'Editar política de reposição' : 'Configurar política de reposição'}</h3>
      <p className="stock-hint">Defina limites em ordem crescente: crítico ≤ segurança ≤ mínimo ≤ alvo. Cada política acompanha um item em um local, mesmo sem saldo registrado.</p>
      {(itemOption?.active === false || locationOption?.active === false) &&
        <p role="status" className="notice">Item ou local inativo. Reative no cadastro antes de alterar a política; a remoção continua disponível.</p>}
      <div className="stock-form-grid">
        <PagedEntitySelect<ItemOption> id="reorder-item" label="Item *" searchLabel="Buscar item para política"
          placeholder="Buscar por SKU ou descrição" emptyLabel="Selecionar item" value={itemId}
          onChange={value => {
            setItemId(value); setItemOption(null); setExisting(false); setLimits({ ...emptyLimits });
            setNotice(''); save.reset(); remove.reset();
          }}
          queryKey={['reorder-item-options']} endpoint="/stock/items" filters={{ active_only: true }}
          initialOption={itemOption} required disabled={save.isPending || remove.isPending}
          optionLabel={item => `${item.sku} — ${item.name}`} />
        <PagedEntitySelect<LocationOption> id="reorder-location" label="Local *" searchLabel="Buscar local para política"
          placeholder="Buscar local" emptyLabel="Selecionar local" value={locationId}
          onChange={value => {
            setLocationId(value); setLocationOption(null); setExisting(false); setLimits({ ...emptyLimits });
            setNotice(''); save.reset(); remove.reset();
          }}
          queryKey={['reorder-location-options']} endpoint="/stock/locations" filters={{ active_only: true }}
          initialOption={locationOption} required disabled={save.isPending || remove.isPending}
          optionLabel={location => location.name} />
        {limitFields.map(({ key, label }) => <div key={key} className="stock-field"><label htmlFor={`reorder-${key}`}>{label} *</label>
          <input id={`reorder-${key}`} required disabled={save.isPending || remove.isPending}
            type="number" inputMode="numeric" min={0} max={maxQuantity}
            step={1} value={limits[key]} onChange={event => {
              setLimits(current => ({ ...current, [key]: event.target.value }));
              setNotice(''); save.reset(); remove.reset();
            }} /></div>)}
      </div>
      {!parsed && Object.values(limits).every(value => value !== '') && <p role="alert" className="notice error">
        Informe inteiros entre 0 e 1.000.000.000 na ordem crítico ≤ segurança ≤ mínimo ≤ alvo.</p>}
      {save.error && <p role="alert" className="notice error">{readableError(save.error)}</p>}
      {remove.error && <p role="alert" className="notice error">{readableError(remove.error)}</p>}
      <div className="inline">
        <button type="submit" className="button primary" disabled={!itemId || !locationId || !parsed ||
          itemOption?.active === false || locationOption?.active === false || save.isPending || remove.isPending}>
          {save.isPending ? 'Salvando...' : 'Salvar política'}</button>
        {existing && <button type="button" className="button ghost" onClick={removePolicy}
          disabled={save.isPending || remove.isPending}><Trash2 size={16}/> Remover política</button>}
        {(existing || itemId || locationId) && <button type="button" className="button ghost"
          onClick={clearEditor} disabled={save.isPending || remove.isPending}>Limpar</button>}
      </div>
    </form>}
  </>;
}
