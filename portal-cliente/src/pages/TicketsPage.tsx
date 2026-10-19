import { useEffect, useState, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, FileImage, Film, Paperclip, Plus, Search, Trash2, X } from 'lucide-react';
import { api, readableError } from '../api';
import { dateTime, label } from '../helpers';
import type { ClientTicket, FleetPrinter, Page, TicketType } from '../types';

const ticketTypes: Array<{ value: TicketType; label: string }> = [
  { value: 'gaveta_quebrada', label: 'Gaveta quebrada' },
  { value: 'painel_atolado', label: 'Impressora atolando papel' },
  { value: 'barulho', label: 'Impressora fazendo muito barulho' },
  { value: 'manchas', label: 'Impressora manchando as folhas' },
  { value: 'toner_reserva', label: 'Solicitação de toner reserva' },
  { value: 'outro', label: 'Outro' },
];
const allowedMimes = new Set([
  'image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm',
]);
const MAX_FILE = 50 * 1024 * 1024;
const MAX_TOTAL = 100 * 1024 * 1024;

const statusLabel: Record<string, string> = {
  triage: 'Em triagem', unassigned: 'Aguardando técnico',
  pending: 'Pendente', in_progress: 'Em atendimento',
  resolved: 'Resolvido', closed: 'Encerrado',
};

function NewTicketDialog({ close, completed }: { close: () => void; completed: (ticket: ClientTicket) => void }) {
  const [category, setCategory] = useState<TicketType | ''>('');
  const [description, setDescription] = useState('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<FleetPrinter[]>([]);
  const [printerId, setPrinterId] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const fleet = useQuery({
    queryKey: ['ticket-printers', search],
    queryFn: () => api<Page<FleetPrinter>>('/client/printers?' + new URLSearchParams({
      per_page: '50', active_only: '1', q: search,
    }).toString(), null),
  });

  useEffect(() => {
    function escape(event: KeyboardEvent) {
      if (event.key === 'Escape' && !saving) close();
    }
    window.addEventListener('keydown', escape);
    return () => window.removeEventListener('keydown', escape);
  }, [close, saving]);

  function addPrinter() {
    const match = fleet.data?.data.find(item => String(item.id) === printerId);
    if (!match || selected.some(item => item.id === match.id)) return;
    if (selected.length >= 5) { setError('Selecione no máximo cinco impressoras.'); return; }
    setSelected(old => [...old, match]);
    setPrinterId('');
    setError(null);
  }

  function addFiles(nextFiles: FileList | null) {
    if (!nextFiles) return;
    const combined = [...files, ...Array.from(nextFiles)];
    if (combined.length > 4) { setError('É permitido anexar até quatro fotos ou vídeos.'); return; }
    if (combined.some(file => !allowedMimes.has(file.type))) {
      setError('Anexe somente JPG, PNG, WebP, MP4, MOV ou WebM.'); return;
    }
    if (combined.some(file => file.size > MAX_FILE)) {
      setError('Cada foto ou vídeo pode ter até 50 MB.'); return;
    }
    if (combined.reduce((total, file) => total + file.size, 0) > MAX_TOTAL) {
      setError('O tamanho total dos anexos não pode superar 100 MB.'); return;
    }
    setFiles(combined);
    setError(null);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!category) { setError('O campo Tipo de chamado é obrigatório.'); return; }
    if (selected.length === 0) { setError('Adicione pelo menos uma impressora.'); return; }
    if (description.trim().length < 10) { setError('Descreva o problema com pelo menos 10 caracteres.'); return; }
    const data = new FormData();
    data.append('category', category);
    data.append('description', description.trim());
    selected.forEach(item => data.append('printer_ids[]', String(item.id)));
    files.forEach(file => data.append('attachments[]', file, file.name));
    setSaving(true);
    setError(null);
    try {
      const answer = await api<{ data: ClientTicket }>('/client/tickets', null, {
        method: 'POST', body: data,
      });
      completed(answer.data);
    } catch (reason) {
      setError(readableError(reason));
    } finally { setSaving(false); }
  }

  return <div className="ticket-overlay" role="presentation" onMouseDown={e => { if (e.target === e.currentTarget && !saving) close(); }}>
    <section role="dialog" aria-modal="true" aria-labelledby="new-ticket-heading" className="ticket-dialog">
      <header className="ticket-dialog-header"><h2 id="new-ticket-heading">Novo chamado</h2>
        <button type="button" aria-label="Fechar" disabled={saving} className="icon-button" onClick={close}><X size={19}/></button>
      </header>
      <form onSubmit={e => void submit(e)} className="ticket-form">
        <label htmlFor="ticket-type">Tipo de chamado <span aria-hidden="true">*</span></label>
        <select required id="ticket-type" value={category}
          onChange={e => setCategory(e.target.value as TicketType | '')}>
          <option value="">Selecione o tipo de chamado</option>
          {ticketTypes.map(type => <option key={type.value} value={type.value}>{type.label}</option>)}
        </select>

        <label htmlFor="ticket-search">Impressoras <span aria-hidden="true">*</span></label>
        <div className="ticket-picker">
          {selected.length > 0 && <div className="table-scroller ticket-chosen"><table>
            <thead><tr><th>Impressora</th><th>Localização</th><th>Departamento</th><th>Ações</th></tr></thead>
            <tbody>{selected.map(item => <tr key={item.id}><td><strong>{item.manufacturer} {item.model}</strong>
              <small>{label(item.serial_number)}</small></td><td>{label(item.location)}</td>
              <td>{label(item.department)}</td><td><button type="button" className="icon-button"
                aria-label={`Remover ${item.model}`} onClick={() => setSelected(old => old.filter(p => p.id !== item.id))}>
                <Trash2 size={16}/></button></td></tr>)}</tbody>
          </table></div>}
          <div className="ticket-picker-search"><Search size={17} aria-hidden="true"/>
            <input id="ticket-search" placeholder="Buscar por modelo, série ou IP" value={search}
              onChange={e => { setSearch(e.target.value); setPrinterId(''); }}/></div>
          <div className="ticket-picker-action"><select aria-label="Impressora para adicionar" value={printerId}
            onChange={e => setPrinterId(e.target.value)}>
            <option value="">Selecione uma impressora vinculada</option>
            {fleet.data?.data.filter(item => !selected.some(p => p.id === item.id)).map(item =>
              <option key={item.id} value={item.id}>{item.manufacturer} {item.model} · {label(item.ip_address)}</option>)}
          </select><button type="button" className="button" onClick={addPrinter}
            disabled={!printerId || selected.length >= 5}><Plus size={15}/> Adicionar</button></div>
          {fleet.isPending && <small>Carregando impressoras autorizadas...</small>}
          {fleet.error && <p className="error" role="alert">{readableError(fleet.error)}</p>}
        </div>

        <label htmlFor="ticket-description">Descrição <span aria-hidden="true">*</span></label>
        <textarea id="ticket-description" rows={5} maxLength={12000} required minLength={10}
          placeholder="Forneça mais detalhes sobre sua solicitação ou problema..."
          value={description} onChange={e => setDescription(e.target.value)}/>

        <label htmlFor="ticket-attachments">Fotos e vídeos</label>
        <div className="ticket-file-upload"><Paperclip size={18} aria-hidden="true"/>
          <span>Selecione fotos ou vídeos para ajudar o técnico a entender o problema.</span>
          <input id="ticket-attachments" type="file" accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.webm"
            multiple onChange={e => { addFiles(e.target.files); e.target.value = ''; }}/></div>
        <small className="ticket-file-hint">Até 4 arquivos, 50 MB por arquivo, 100 MB no total. JPG, PNG, WebP, MP4, MOV ou WebM.</small>
        {files.length > 0 && <ul className="ticket-attachments-list">{files.map((file, index) =>
          <li key={index}>{file.type.startsWith('video/') ? <Film size={17}/> : <FileImage size={17}/>}
            <span>{file.name} ({(file.size / 1024 / 1024).toFixed(1)} MB)</span>
            <button type="button" className="icon-button" aria-label={`Remover anexo ${file.name}`}
              onClick={() => setFiles(old => old.filter((_, i) => i !== index))}><X size={17}/></button>
          </li>)}</ul>}
        {error && <p className="error" role="alert">{error}</p>}
        <footer className="ticket-form-actions">
          <button type="submit" className="button primary" disabled={saving}>{saving ? 'Enviando...' : 'Salvar chamado'}</button>
          <button type="button" className="button" disabled={saving} onClick={close}>Cancelar</button>
        </footer>
      </form>
    </section>
  </div>;
}

function TicketDetails({ ticket, close }: { ticket: ClientTicket; close: () => void }) {
  return <div className="ticket-overlay" role="presentation" onMouseDown={e => { if (e.target === e.currentTarget) close(); }}>
    <section role="dialog" aria-modal="true" aria-labelledby="ticket-detail-heading" className="ticket-dialog">
      <header className="ticket-dialog-header"><h2 id="ticket-detail-heading">Chamado #{ticket.id}</h2>
        <button className="icon-button" aria-label="Fechar detalhes" onClick={close}><X size={19}/></button></header>
      <div className="ticket-detail-content">
        <p><strong>Tipo:</strong> {ticket.subject}</p>
        <p><strong>Situação:</strong> {statusLabel[ticket.status] ?? ticket.status}</p>
        <p><strong>Data de abertura:</strong> {dateTime(ticket.created_at)}</p>
        <h3>Descrição</h3><p className="ticket-description">{ticket.description}</p>
        <h3>Impressoras</h3>
        <ul>{ticket.printers.map(p => <li key={p.id}>{p.manufacturer} {p.model} — {label(p.ip_address)}</li>)}</ul>
        {ticket.signature && <section>
          <h3>Confirmação do serviço</h3>
          <p><strong>Assinado por:</strong> {ticket.signature.signer_name}</p>
          <p><strong>Serviço realizado:</strong> {ticket.signature.service_summary}</p>
          <p><strong>Data do aceite:</strong> {dateTime(ticket.signature.signed_at)}</p>
        </section>}
        <h3>Fotos e vídeos anexados</h3>
        {ticket.attachments.length === 0 ? <p>Nenhum anexo.</p>
          : <ul className="ticket-attachments-list">{ticket.attachments.map(file =>
            <li key={file.id}>{file.mime_type.startsWith('video/') ? <Film size={18}/> : <FileImage size={18}/>}
              <a href={`/api/v1/browser/client/tickets/${ticket.id}/attachments/${file.id}`}>
                {file.name}</a><small>{(file.size / 1024 / 1024).toFixed(1)} MB</small></li>)}</ul>}
        <p className="ticket-file-hint">Anexos exigem uma sessão válida e são disponibilizados para download privado.</p>
      </div>
      <footer className="ticket-form-actions"><button type="button" className="button" onClick={close}>Fechar</button></footer>
    </section>
  </div>;
}

export function TicketsPage() {
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [selectedTicket, setSelectedTicket] = useState<ClientTicket | null>(null);
  const cache = useQueryClient();
  const tickets = useQuery({
    queryKey: ['client-tickets', page],
    queryFn: () => api<Page<ClientTicket>>(`/client/tickets?page=${page}&per_page=20`, null),
  });
  return <>
    <div className="reference-page-title"><ClipboardList size={22} aria-hidden="true"/><h1>Chamados</h1></div>
    <section className="report-panel client-tickets-panel">
      <div className="ticket-toolbar">
        <button type="button" className="button primary" onClick={() => setCreating(true)}><Plus size={18}/> Abrir chamado</button>
      </div>
      {tickets.isPending ? <p role="status" className="empty">Carregando chamados...</p>
        : tickets.error ? <p role="alert" className="error">{readableError(tickets.error)}</p>
        : !tickets.data?.data.length ? <p className="empty">Você ainda não possui chamados neste cliente.</p>
        : <div className="table-scroller"><table><thead><tr><th>#</th><th>Tipo</th>
          <th>Data de abertura</th><th>Situação</th><th>Impressoras</th><th>Ações</th></tr></thead>
          <tbody>{tickets.data.data.map(ticket => <tr key={ticket.id}>
            <td>#{ticket.id}</td><td><strong>{ticket.subject}</strong></td>
            <td>{dateTime(ticket.created_at)}</td>
            <td><span className="status active">{statusLabel[ticket.status] ?? ticket.status}</span></td>
            <td>{ticket.printers.map(p => p.model).join(', ') || '—'}</td>
            <td><button type="button" className="button" onClick={() => setSelectedTicket(ticket)}>
              Ver detalhes</button></td></tr>)}</tbody></table></div>}
      {tickets.data && <div className="pagination"><span>{tickets.data.total ?? 0} chamados · Página {page}</span>
        <div className="pagination-buttons"><button type="button" disabled={page <= 1}
          onClick={() => setPage(p => p - 1)}>Anterior</button>
          <button type="button" disabled={page >= (tickets.data.last_page ?? 1)}
            onClick={() => setPage(p => p + 1)}>Próxima</button></div></div>}
    </section>
    <p className="footnote">Somente chamados de impressoras vinculadas ao seu acesso aparecem aqui.
      Para acrescentar evidências a um chamado já aberto, contate o suporte da prestadora.</p>
    {creating && <NewTicketDialog close={() => setCreating(false)} completed={ticket => {
      setCreating(false); setSelectedTicket(ticket); setPage(1);
      void cache.invalidateQueries({ queryKey: ['client-tickets'] });
    }}/>}
    {selectedTicket && <TicketDetails ticket={selectedTicket} close={() => setSelectedTicket(null)}/>}
  </>;
}
