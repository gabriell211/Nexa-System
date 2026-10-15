import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, MapPin, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import { PagedEntitySelect } from '../components/PagedEntitySelect';
import type { Page, Printer } from '../types';

interface Unit { id: number; name: string; active?: boolean }
interface Department { id: number; name: string; active?: boolean }
interface CostCenter { id: number; code: string; name: string; active?: boolean }
interface Assignment {
  id: number; location_id: number; department_id: number | null;
  cost_center_id: number | null; assigned_at: string; released_at: string | null;
  reason: string; location?: { id: number; name: string };
  department?: { id: number; name: string } | null;
  cost_center?: { id: number; code: string; name: string } | null;
  actor?: { id: number; name: string } | null;
}

export function PrinterAssignmentPanel({ printer, token, canWrite, onClose }: {
  printer: Printer; token: string | null; canWrite: boolean; onClose: () => void;
}) {
  const qc = useQueryClient();
  const [locationId, setLocationId] = useState(String(printer.current_assignment?.location_id ?? ''));
  const [departmentId, setDepartmentId] = useState(String(printer.current_assignment?.department_id ?? ''));
  const [costCenterId, setCostCenterId] = useState(String(printer.current_assignment?.cost_center_id ?? ''));
  const [reason, setReason] = useState('');
  const [historyPage, setHistoryPage] = useState(1);
  const [notice, setNotice] = useState<string | null>(null);

  const url = '/printers/' + printer.id + '/assignments';
  const customerBase = '/customers/' + printer.customer_id;
  const history = useQuery({
    queryKey: ['printer-assignments', printer.id, historyPage],
    queryFn: () => api<Page<Assignment>>(url + queryString({ page: historyPage, per_page: 25 }), token),
  });
  const currentAssignment = useQuery({
    queryKey: ['printer-assignment-current', printer.id],
    queryFn: () => api<Page<Assignment>>(url + queryString({ page: 1, per_page: 1 }), token),
  });
  async function refresh() {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['printers'] }),
      qc.invalidateQueries({ queryKey: ['printer-assignments', printer.id] }),
      qc.invalidateQueries({ queryKey: ['printer-assignment-current', printer.id] }),
    ]);
  }

  const assign = useMutation({
    mutationFn: () => api<unknown>(url, token, {
      method: 'POST',
      body: JSON.stringify({
        location_id: Number(locationId),
        department_id: departmentId ? Number(departmentId) : null,
        cost_center_id: costCenterId ? Number(costCenterId) : null,
        reason: reason.trim(),
      }),
    }),
    onSuccess: async () => {
      setReason('');
      setHistoryPage(1);
      setNotice('Nova localização registrada com sucesso.');
      await refresh();
    },
  });

  const release = useMutation({
    mutationFn: () => api<void>('/printers/' + printer.id + '/unassign', token, {
      method: 'POST', body: JSON.stringify({ reason: reason.trim() }),
    }),
    onSuccess: async () => {
      setLocationId(''); setDepartmentId(''); setCostCenterId(''); setReason('');
      setHistoryPage(1);
      setNotice('Impressora retirada da localização. Histórico preservado.');
      await refresh();
    },
  });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    if (locationId && reason.trim().length >= 5) assign.mutate();
  }

  const current = currentAssignment.data?.data.find(item => item.released_at === null);
  const currentPage = history.data?.meta?.current_page ?? history.data?.current_page ?? historyPage;
  const lastPage = history.data?.meta?.last_page ?? history.data?.last_page ?? 1;
  const total = history.data?.meta?.total ?? history.data?.total ?? 0;
  const formPending = assign.isPending || release.isPending;
  const historyError = history.error || currentAssignment.error;

  return <div className="modal-overlay">
    <section role="dialog" aria-modal="true" aria-labelledby="assign-printer-heading" className="modal printer-assignment-modal">
      <div className="modal-header">
        <div><span className="eyebrow">LOCALIZAÇÃO E HISTÓRICO</span>
          <h2 id="assign-printer-heading">{printer.manufacturer} {printer.model}</h2>
          <p className="modal-subtitle">{printer.customer?.name ?? 'Cliente'} • Impressora #{printer.id}</p>
        </div>
        <button type="button" className="icon-button" aria-label="Fechar" onClick={onClose}><X size={20}/></button>
      </div>
      <div className="current-location">
        <MapPin size={20} aria-hidden="true"/>
        <div><strong>Localização atual</strong>
          <p>{currentAssignment.isPending ? 'Consultando...' : current
            ? [current.location?.name, current.department?.name, current.cost_center?.code].filter(Boolean).join(' / ')
            : 'Sem unidade atribuída'}</p>
        </div>
      </div>
      {notice && <p className="notice success" role="status">{notice}</p>}
      {(historyError || assign.error || release.error) &&
        <p role="alert" className="notice error">{readableError(historyError || assign.error || release.error)}</p>}
      {canWrite && printer.active && <form className="editor-form" onSubmit={submit}>
        <span className="eyebrow">REGISTRAR MOVIMENTAÇÃO</span>
        <PagedEntitySelect<Unit> id="assigned-location" label="Unidade do cliente *"
          searchLabel="Buscar unidade da impressora" placeholder="Buscar unidade por nome ou código..."
          emptyLabel="Selecione uma unidade ativa" value={locationId}
          onChange={value => { setLocationId(value); setDepartmentId(''); }}
          queryKey={['printer-location-options', printer.customer_id]}
          endpoint={customerBase + '/locations'} filters={{ active_only: true }}
          optionLabel={unit => unit.name}
          initialOption={current?.location ?? printer.current_assignment?.location}
          required disabled={formPending}/>
        <div className="form-columns">
          <PagedEntitySelect<Department> key={locationId || 'no-unit'}
            id="assigned-dept" label="Departamento" searchLabel="Buscar departamento da impressora"
            placeholder="Buscar departamento por nome ou código..." emptyLabel="Não vincular"
            value={departmentId} onChange={setDepartmentId}
            queryKey={['printer-departments', printer.customer_id, locationId]}
            endpoint={customerBase + '/locations/' + locationId + '/departments'}
            filters={{ active_only: true }} optionLabel={dept => dept.name}
            initialOption={current?.location_id === Number(locationId) ? current.department : null}
            disabled={!locationId || formPending}/>
          <PagedEntitySelect<CostCenter> id="assigned-center" label="Centro de custo"
            searchLabel="Buscar centro de custo da impressora"
            placeholder="Buscar centro por nome ou código..." emptyLabel="Não vincular"
            value={costCenterId} onChange={setCostCenterId}
            queryKey={['printer-center-options', printer.customer_id]}
            endpoint={customerBase + '/cost-centers'} filters={{ active_only: true }}
            optionLabel={center => `${center.code} — ${center.name}`}
            initialOption={current?.cost_center ?? null} disabled={formPending}/>
        </div>
        <label htmlFor="assigned-reason">Motivo da movimentação *</label>
        <input id="assigned-reason" required minLength={5} maxLength={500}
          placeholder="Ex.: Instalada na recepção do setor"
          value={reason} onChange={event => setReason(event.target.value)}/>
        <div className="modal-actions">
          <button type="submit" className="button primary"
            disabled={!locationId || formPending}>
            <ArrowRightLeft size={17} /> {formPending ? 'Registrando...' : 'Registrar localização'}
          </button>
          {current && <button type="button" className="button ghost" disabled={formPending || reason.trim().length < 5}
            onClick={() => {
              if (window.confirm('Registrar retirada desta impressora? O histórico será mantido.')) release.mutate();
            }}>Registrar retirada</button>}
        </div>
      </form>}
      <div className="assignment-history">
        <h3>Histórico de movimentações</h3>
        {history.isPending ? <p>Carregando histórico...</p> : history.data?.data.length === 0
          ? <p>{total ? 'Nenhuma movimentação nesta página.' : 'Sem movimentações registradas.'}</p>
          : <div className="table-wrap"><table><thead><tr>
            <th>Local</th><th>Início</th><th>Fim</th><th>Motivo</th>
          </tr></thead><tbody>
            {(history.data?.data ?? []).map(item => <tr key={item.id}>
              <td><strong>{item.location?.name ?? '—'}</strong><small>{item.department?.name ?? 'Sem departamento'}</small></td>
              <td>{new Date(item.assigned_at).toLocaleString('pt-BR')}</td>
              <td>{item.released_at ? new Date(item.released_at).toLocaleString('pt-BR') : 'Atual'}</td>
              <td>{item.reason}</td>
            </tr>)}
          </tbody></table></div>}
        {history.data && <div className="pagination">
          <span>{total} movimentações • Página {currentPage} de {lastPage}</span>
          <div className="inline">
            <button type="button" className="button ghost" disabled={history.isPending || currentPage <= 1}
              onClick={() => setHistoryPage(currentPage - 1)}>Anterior</button>
            <button type="button" className="button ghost" disabled={history.isPending || currentPage >= lastPage}
              onClick={() => setHistoryPage(currentPage + 1)}>Próxima</button>
          </div>
        </div>}
      </div>
    </section>
  </div>;
}
