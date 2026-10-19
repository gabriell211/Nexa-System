import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, History, MapPin, Pencil, Save, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page, Printer } from '../types';

type Tab = 'general' | 'history' | 'actions';
type Action = { id: number; action: string; actor: string | null; created_at: string };
type Reading = {
  id: number; meter_total: string | null; meter_mono: string | null;
  meter_color: string | null; meter_scan: string | null;
  collected_at: string; received_at: string;
};
type InventoryFields = Pick<Printer, 'asset_number' | 'mac_address' | 'firmware_version'
  | 'owner_type' | 'connection_type' | 'color_capability'>;

const ownerNames = { unknown: 'Não informado', customer: 'Cliente', provider: 'Prestadora' };
const colorNames = { unknown: 'Não determinado', mono: 'Monocromática', color: 'Colorida' };
const connectionNames = { unknown: 'Não informado', network: 'Rede', usb: 'USB' };
const eventNames: Record<string,string> = {
  'printer.created': 'Impressora cadastrada',
  'printer.updated': 'Dados gerais atualizados',
  'printer.deactivated': 'Impressora inativada',
  'printer.assignment.changed': 'Localização alterada',
  'printer.assignment.released': 'Impressora retirada da localização',
};
const format = (date: string | undefined | null) => {
  if (!date) return 'Não informado';
  const value = new Date(date);
  return Number.isNaN(value.getTime()) ? 'Data inválida' : value.toLocaleString('pt-BR');
};
const counter = (value: string | null) => {
  if (value === null) return '—';
  try { return BigInt(value).toLocaleString('pt-BR'); } catch { return 'Não disponível'; }
};
function pagination<T>(response: Page<T> | undefined) {
  return {
    current: response?.meta?.current_page ?? response?.current_page ?? 1,
    pages: response?.meta?.last_page ?? response?.last_page ?? 1,
    total: response?.meta?.total ?? response?.total ?? 0,
  };
}

function RowsPagination({ current, pages, total, onPage }: {
  current: number; pages: number; total: number; onPage: (page: number) => void
}) {
  return <div className="pagination"><span>{total} registro(s) · Página {current} de {Math.max(pages,1)}</span>
    <div className="inline">
      <button type="button" className="button ghost" disabled={current <= 1}
        onClick={() => onPage(current - 1)}>Anterior</button>
      <button type="button" className="button ghost" disabled={current >= pages}
        onClick={() => onPage(current + 1)}>Próxima</button>
    </div></div>;
}

export function PrinterDetailsPanel({ id, token, canWrite, close, move }: {
  id: number; token: string | null; canWrite: boolean; close: () => void;
  move: (printer: Printer) => void;
}) {
  const [tab, setTab] = useState<Tab>('general');
  const [historyPage, setHistoryPage] = useState(1);
  const [actionPage, setActionPage] = useState(1);
  const [editing, setEditing] = useState(false);
  const [fields, setFields] = useState<InventoryFields | null>(null);
  const [notice, setNotice] = useState('');
  const qc = useQueryClient();
  const detail = useQuery({
    queryKey: ['printer-detail', id],
    queryFn: () => api<{ data: Printer }>('/printers/' + id, token),
  });
  const printer = detail.data?.data;
  const readings = useQuery({
    queryKey: ['printer-detail-readings', id, historyPage],
    queryFn: () => api<Page<Reading>>('/printers/' + id + '/readings'
      + queryString({ page: historyPage, per_page: 20 }), token),
    enabled: tab === 'history',
  });
  const actions = useQuery({
    queryKey: ['printer-actions', id, actionPage],
    queryFn: () => api<Page<Action>>('/printers/' + id + '/actions'
      + queryString({ page: actionPage, per_page: 20 }), token),
    enabled: tab === 'actions',
  });
  const update = useMutation({
    mutationFn: (data: InventoryFields) => api<{ data: Printer }>('/printers/' + id, token, {
      method: 'PATCH',
      body: JSON.stringify(data),
    }),
    onSuccess: async () => {
      setEditing(false);
      setNotice('Dados de inventário atualizados e registrados na auditoria.');
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['printers'] }),
        qc.invalidateQueries({ queryKey: ['printer-detail', id] }),
        qc.invalidateQueries({ queryKey: ['printer-actions', id] }),
      ]);
    },
  });
  function beginEdit() {
    if (!printer) return;
    setFields({
      asset_number: printer.asset_number, mac_address: printer.mac_address,
      firmware_version: printer.firmware_version,
      owner_type: printer.owner_type, connection_type: printer.connection_type,
      color_capability: printer.color_capability,
    });
    setEditing(true); setNotice('');
  }
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (fields) update.mutate(fields);
  }
  const location = printer?.current_assignment;
  const position = [location?.location?.name, location?.department?.name,
    location?.cost_center?.name].filter(Boolean).join(' / ') || 'Sem localização atribuída';

  return <div className="modal-overlay">
    <section className="modal printer-details-modal" role="dialog" aria-modal="true"
      aria-labelledby="printer-details-heading">
      <div className="modal-header">
        <div><span className="eyebrow">PARQUE DE IMPRESSÃO</span>
          <h2 id="printer-details-heading">Detalhes da impressora</h2>
          {printer && <p className="modal-subtitle">{printer.manufacturer} {printer.model} · {printer.customer?.name}</p>}
        </div>
        <button type="button" className="icon-button" aria-label="Fechar detalhes da impressora" onClick={close}><X size={20}/></button>
      </div>
      <div className="printer-tabs" role="tablist" aria-label="Informações da impressora">
        {([['general','Dados gerais'],['history','Histórico de leituras'],['actions','Histórico de ações']] as const).map(([key,label]) =>
          <button key={key} id={'printer-tab-'+key} type="button" role="tab"
            aria-selected={tab===key} aria-controls={'printer-panel-'+key}
            className={'printer-tab' + (tab === key ? ' selected' : '')}
            onClick={() => {setTab(key);setEditing(false);}}>
            {label}
          </button>)}
      </div>
      <div className="printer-details-content">
        {detail.isPending ? <p role="status">Carregando dados do equipamento...</p>
          : detail.error ? <p role="alert" className="notice error">{readableError(detail.error)}</p>
          : printer && tab === 'general' ? <div id="printer-panel-general" role="tabpanel" aria-labelledby="printer-tab-general">
            {notice && <p role="status" className="notice success">{notice}</p>}
            {editing && fields ? <form className="printer-metadata-form editor-form" onSubmit={submit}>
              <div className="printer-details-grid">
                <div><label htmlFor="asset-no">Número de patrimônio</label>
                  <input id="asset-no" maxLength={80} placeholder="Não informado"
                    value={fields.asset_number ?? ''} onChange={e => setFields({...fields, asset_number: e.target.value || null})}/></div>
                <div><label htmlFor="mac-addr">Endereço MAC</label>
                  <input id="mac-addr" maxLength={17} placeholder="AA:BB:CC:DD:EE:FF"
                    value={fields.mac_address ?? ''} onChange={e => setFields({...fields, mac_address: e.target.value || null})}/></div>
                <div><label htmlFor="firmware-ver">Firmware</label>
                  <input id="firmware-ver" maxLength={160} placeholder="Não informado"
                    value={fields.firmware_version ?? ''} onChange={e => setFields({...fields, firmware_version: e.target.value || null})}/></div>
                <div><label htmlFor="owner-type">Proprietário</label><select id="owner-type"
                  value={fields.owner_type} onChange={e => setFields({...fields, owner_type: e.target.value as Printer['owner_type']})}>
                  {Object.entries(ownerNames).map(([value,label])=><option key={value} value={value}>{label}</option>)}
                </select></div>
                <div><label htmlFor="connection-type">Tipo de conexão</label><select id="connection-type"
                  value={fields.connection_type} onChange={e => setFields({...fields, connection_type: e.target.value as Printer['connection_type']})}>
                  {Object.entries(connectionNames).map(([value,label])=><option key={value} value={value}>{label}</option>)}
                </select></div>
                <div><label htmlFor="color-type">Tipo de impressão</label><select id="color-type"
                  value={fields.color_capability} onChange={e => setFields({...fields, color_capability: e.target.value as Printer['color_capability']})}>
                  {Object.entries(colorNames).map(([value,label])=><option key={value} value={value}>{label}</option>)}
                </select></div>
              </div>
              {update.error && <p role="alert" className="notice error">{readableError(update.error)}</p>}
              <p className="stock-footnote">Preencha somente dados verificados; nenhum número de patrimônio, MAC ou firmware é inferido.</p>
              <div className="modal-actions">
                <button type="button" className="button ghost" disabled={update.isPending}
                  onClick={() => setEditing(false)}>Cancelar</button>
                <button type="submit" className="button primary" disabled={update.isPending}>
                  <Save size={16}/>{update.isPending ? 'Salvando...' : 'Salvar alterações'}</button>
              </div>
            </form> : <>
              <dl className="printer-details-list">
                <dt>Fabricante</dt><dd><strong>{printer.manufacturer}</strong></dd>
                <dt>Modelo</dt><dd><strong>{printer.model}</strong></dd>
                <dt>Número de série</dt><dd>{printer.serial_number || 'Não informado'}</dd>
                <dt>Endereço IP</dt><dd className="mono">{printer.ip_address || 'Não informado'}</dd>
                <dt>Endereço MAC</dt><dd className="mono">{printer.mac_address || 'Não informado'}</dd>
                <dt>Firmware</dt><dd>{printer.firmware_version || 'Não informado'}</dd>
                <dt>Proprietário</dt><dd>{ownerNames[printer.owner_type]}</dd>
                <dt>Nº de patrimônio</dt><dd>{printer.asset_number || 'Não informado'}</dd>
                <dt>Tipo de conexão</dt><dd>{connectionNames[printer.connection_type]}</dd>
                <dt>Capacidade de cor</dt><dd>{colorNames[printer.color_capability]}</dd>
                <dt>Situação</dt><dd>{printer.active ? 'Faz parte do parque' : 'Inativa no cadastro'} · {printer.status === 'unknown' ? 'Estado de coleta desconhecido' : printer.status}</dd>
                <dt>Última leitura recebida</dt><dd>{format(printer.latest_reading?.received_at)}</dd>
                <dt>Localização atual</dt><dd>{position}</dd>
              </dl>
              <p className="stock-footnote">Ativa no cadastro não significa online. MAC e firmware são dados manuais até identificação homologada.</p>
              <div className="modal-actions">
                {canWrite && <button type="button" className="button ghost" onClick={beginEdit}><Pencil size={16}/> Editar dados gerais</button>}
                <button type="button" className="button ghost" onClick={() => { close(); move(printer); }}>
                  <MapPin size={16}/> Localização e movimentações</button>
                <button type="button" className="button primary" onClick={close}>Fechar</button>
              </div>
            </>}
          </div> : tab === 'history' ? <div id="printer-panel-history" role="tabpanel" aria-labelledby="printer-tab-history">
            <p className="stock-footnote">Amostras reais de contador. Ausência não significa zero; totais não representam automaticamente páginas faturáveis.</p>
            {readings.isPending ? <p role="status">Carregando leituras...</p>
              : readings.error ? <p role="alert" className="notice error">{readableError(readings.error)}</p>
              : !readings.data?.data.length ? <p>Nenhum contador registrado.</p>
              : <div className="table-wrap"><table><thead><tr><th>Coleta</th><th>Recebimento</th><th>Total</th><th>P&B</th><th>Cor</th><th>Digitalizações</th></tr></thead>
                <tbody>{readings.data.data.map(item=><tr key={item.id}><td>{format(item.collected_at)}</td><td>{format(item.received_at)}</td>
                  <td>{counter(item.meter_total)}</td><td>{counter(item.meter_mono)}</td><td>{counter(item.meter_color)}</td><td>{counter(item.meter_scan)}</td></tr>)}</tbody></table></div>}
            {readings.data && <RowsPagination {...pagination(readings.data)} onPage={setHistoryPage}/>}
          </div> : <div id="printer-panel-actions" role="tabpanel" aria-labelledby="printer-tab-actions">
            <p className="stock-footnote"><ClipboardList size={15} aria-hidden="true"/> Eventos de cadastro e movimentação registrados por operadores autorizados.</p>
            {actions.isPending ? <p role="status">Carregando histórico de ações...</p>
              : actions.error ? <p role="alert" className="notice error">{readableError(actions.error)}</p>
              : !actions.data?.data.length ? <p>Nenhuma ação auditada para esta impressora.</p>
              : <div className="table-wrap"><table><thead><tr><th>Evento</th><th>Operador</th><th>Data e hora</th></tr></thead>
                <tbody>{actions.data.data.map(item => <tr key={item.id}><td><History size={15}/> {eventNames[item.action] ?? item.action}</td>
                  <td>{item.actor || 'Sistema'}</td><td>{format(item.created_at)}</td></tr>)}</tbody></table></div>}
            {actions.data && <RowsPagination {...pagination(actions.data)} onPage={setActionPage}/>}
          </div>}
      </div>
    </section>
  </div>;
}
