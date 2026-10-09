import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, MapPin, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Page, Printer } from '../types';

interface Unit { id: number; name: string; active: boolean }
interface Department { id: number; name: string; active: boolean }
interface CostCenter { id: number; code: string; name: string; active: boolean }
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
  const [notice, setNotice] = useState<string | null>(null);

  const url = '/printers/' + printer.id + '/assignments';
  const customerBase = '/customers/' + printer.customer_id;
  const history = useQuery({
    queryKey: ['printer-assignments', printer.id],
    queryFn: () => api<Page<Assignment>>(url, token),
  });
  const units = useQuery({
    queryKey: ['printer-location-options', printer.customer_id],
    enabled: canWrite,
    queryFn: () => api<Page<Unit>>(customerBase + '/locations' + queryString({
      active_only: true, per_page: 100,
    }), token),
  });
  const centers = useQuery({
    queryKey: ['printer-center-options', printer.customer_id],
    enabled: canWrite,
    queryFn: () => api<Page<CostCenter>>(customerBase + '/cost-centers' + queryString({
      active_only: true, per_page: 100,
    }), token),
  });
  const departments = useQuery({
    queryKey: ['printer-departments', printer.customer_id, locationId],
    enabled: canWrite && locationId !== '',
    queryFn: () => api<Page<Department>>(
      customerBase + '/locations/' + locationId + '/departments' +
      queryString({ active_only: true, per_page: 100 }), token,
    ),
  });

  async function refresh() {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['printers'] }),
      qc.invalidateQueries({ queryKey: ['printer-assignments', printer.id] }),
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
      setNotice('Impressora retirada da localização. Histórico preservado.');
      await refresh();
    },
  });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    if (locationId && reason.trim().length >= 5) assign.mutate();
  }

  const current = history.data?.data.find(item => item.released_at === null);
  const formPending = assign.isPending || release.isPending;
  const optionLoading = units.isPending || centers.isPending || (locationId !== '' && departments.isPending);
  const optionError = units.error || centers.error || departments.error;
  const historyError = history.error;

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
          <p>{history.isPending ? 'Consultando...' : current
            ? [current.location?.name, current.department?.name, current.cost_center?.code].filter(Boolean).join(' / ')
            : 'Sem unidade atribuída'}</p>
        </div>
      </div>
      {notice && <p className="notice success" role="status">{notice}</p>}
      {(historyError || optionError || assign.error || release.error) &&
        <p role="alert" className="notice error">{readableError(historyError || optionError || assign.error || release.error)}</p>}
      {canWrite && printer.active && <form className="editor-form" onSubmit={submit}>
        <span className="eyebrow">REGISTRAR MOVIMENTAÇÃO</span>
        <label htmlFor="assigned-location">Unidade do cliente *</label>
        <select id="assigned-location" required disabled={optionLoading || formPending}
          value={locationId} onChange={event => {
            setLocationId(event.target.value); setDepartmentId('');
          }}>
          <option value="">Selecione uma unidade ativa</option>
          {(units.data?.data ?? []).map(unit => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
        </select>
        <div className="form-columns">
          <div><label htmlFor="assigned-dept">Departamento</label>
            <select id="assigned-dept" disabled={!locationId || optionLoading || formPending} value={departmentId}
              onChange={event => setDepartmentId(event.target.value)}>
              <option value="">Não vincular</option>
              {(departments.data?.data ?? []).map(dept => <option key={dept.id} value={dept.id}>{dept.name}</option>)}
            </select>
          </div>
          <div><label htmlFor="assigned-center">Centro de custo</label>
            <select id="assigned-center" disabled={optionLoading || formPending} value={costCenterId}
              onChange={event => setCostCenterId(event.target.value)}>
              <option value="">Não vincular</option>
              {(centers.data?.data ?? []).map(center =>
                <option key={center.id} value={center.id}>{center.code} — {center.name}</option>)}
            </select>
          </div>
        </div>
        <label htmlFor="assigned-reason">Motivo da movimentação *</label>
        <input id="assigned-reason" required minLength={5} maxLength={500}
          placeholder="Ex.: Instalada na recepção do setor"
          value={reason} onChange={event => setReason(event.target.value)}/>
        <div className="modal-actions">
          <button type="submit" className="button primary"
            disabled={!locationId || formPending || optionLoading || Boolean(optionError)}>
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
          ? <p>Sem movimentações registradas.</p>
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
      </div>
    </section>
  </div>;
}
