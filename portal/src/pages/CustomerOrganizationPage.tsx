import { useState, type FormEvent, type ReactNode } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, MapPin, Pencil, Plus, Trash2, Users, X } from 'lucide-react';
import { api, queryString, readableError } from '../api';
import type { Customer, Page } from '../types';

interface Location {
  id: number; customer_id: number; name: string; code: string | null;
  document: string | null; address_line: string | null; number: string | null;
  district: string | null; city: string | null; state: string | null;
  postal_code: string | null; country: string; active: boolean;
  departments_count?: number;
}
interface Department {
  id: number; customer_id: number; location_id: number; name: string;
  code: string | null; responsible_name: string | null;
  responsible_email: string | null; active: boolean;
}
interface CostCenter {
  id: number; customer_id: number; code: string; name: string; active: boolean;
}
type Kind = 'location' | 'department' | 'center';
type RecordItem = Location | Department | CostCenter;
interface Editor { kind: Kind; record: RecordItem | null; base: string }
interface FormFields {
  name: string; code: string; document: string; address_line: string;
  number: string; district: string; city: string; state: string;
  postal_code: string; country: string; responsible_name: string;
  responsible_email: string; active: boolean;
}

function initialFields(record: RecordItem | null): FormFields {
  const obj = record as unknown as Partial<FormFields> | null;
  return {
    name: obj?.name ?? '', code: obj?.code ?? '', document: obj?.document ?? '',
    address_line: obj?.address_line ?? '', number: obj?.number ?? '',
    district: obj?.district ?? '', city: obj?.city ?? '', state: obj?.state ?? '',
    postal_code: obj?.postal_code ?? '', country: obj?.country ?? 'BR',
    responsible_name: obj?.responsible_name ?? '', responsible_email: obj?.responsible_email ?? '',
    active: obj?.active ?? true,
  };
}

function labelFor(kind: Kind): string {
  return kind === 'location' ? 'unidade' : kind === 'department' ? 'departamento' : 'centro de custo';
}

function EditorDialog({ editing, pending, onCancel, onSubmit }: {
  editing: Editor; pending: boolean; onCancel: () => void;
  onSubmit: (payload: Record<string, string | null | boolean>) => Promise<void>;
}) {
  const [fields, setFields] = useState(() => initialFields(editing.record));
  const [error, setError] = useState<string | null>(null);
  function set(name: keyof FormFields, value: string | boolean) {
    setFields(previous => ({ ...previous, [name]: value }));
  }
  function field(name: keyof Omit<FormFields, 'active'>, label: string, required = false, type = 'text'): ReactNode {
    return <div className="field-block" key={name}>
      <label htmlFor={'org-' + name}>{label}</label>
      <input id={'org-' + name} required={required} type={type}
        maxLength={name === 'name' ? 180 : name === 'code' ? 64 : name === 'document' ? 32 : 240}
        value={String(fields[name])} onChange={e => set(name, e.target.value)} />
    </div>;
  }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    const required = {
      name: fields.name.trim(), code: fields.code.trim(),
      active: fields.active,
    };
    if (required.name.length < 2) {
      setError('Informe um nome com pelo menos dois caracteres.'); return;
    }
    const nullable = (text: string) => text.trim() || null;
    const payload = editing.kind === 'location'
      ? { ...required, code: nullable(fields.code), document: nullable(fields.document),
          address_line: nullable(fields.address_line), number: nullable(fields.number),
          district: nullable(fields.district), city: nullable(fields.city), state: nullable(fields.state),
          postal_code: nullable(fields.postal_code), country: fields.country.toUpperCase() }
      : editing.kind === 'department'
      ? { ...required, code: nullable(fields.code), responsible_name: nullable(fields.responsible_name),
          responsible_email: nullable(fields.responsible_email) }
      : required;
    try { await onSubmit(payload); } catch (cause) { setError(readableError(cause)); }
  }

  return <div className="modal-overlay"><section className="modal" role="dialog" aria-modal="true" aria-labelledby="organization-modal-title">
    <div className="modal-header"><div><span className="eyebrow">ESTRUTURA DO CLIENTE</span>
      <h2 id="organization-modal-title">{editing.record ? 'Editar' : 'Adicionar'} {labelFor(editing.kind)}</h2></div>
      <button className="icon-button" type="button" onClick={onCancel} aria-label="Fechar"><X size={20}/></button>
    </div>
    <form className="editor-form" onSubmit={event => void submit(event)}>
      {field('name', 'Nome', true)}
      {field('code', 'Código' + (editing.kind === 'center' ? ' *' : ''), editing.kind === 'center')}
      {editing.kind === 'location' && <>
        {field('document', 'CNPJ/CPF da unidade (quando próprio)')}
        {field('address_line', 'Logradouro')}
        <div className="form-columns">{field('number', 'Número')}{field('district', 'Bairro')}</div>
        <div className="form-columns">{field('city', 'Cidade')}{field('state', 'Estado')}</div>
        <div className="form-columns">{field('postal_code', 'CEP')}{field('country', 'País (ISO 2 letras)', true)}</div>
      </>}
      {editing.kind === 'department' && <>
        {field('responsible_name', 'Nome do responsável')}
        {field('responsible_email', 'E-mail do responsável', false, 'email')}
      </>}
      {editing.record && <label className="check-row">
        <input type="checkbox" checked={fields.active} onChange={event => set('active', event.target.checked)} /> Ativo
      </label>}
      {error && <p className="notice error" role="alert">{error}</p>}
      <div className="modal-actions">
        <button className="button ghost" type="button" onClick={onCancel}>Cancelar</button>
        <button className="button primary" disabled={pending} type="submit">{pending ? 'Salvando...' : 'Salvar'}</button>
      </div>
    </form>
  </section></div>;
}

function Pagination({ value, onChange }: {
  value: Page<RecordItem> | undefined; onChange: (page: number) => void;
}) {
  const current = value?.meta?.current_page ?? 1;
  const last = value?.meta?.last_page ?? 1;
  const total = value?.meta?.total ?? 0;
  return <div className="pagination"><span>{total} registros • Página {current}/{last}</span><div className="inline">
    <button className="button ghost" disabled={current <= 1} onClick={() => onChange(current - 1)}>Anterior</button>
    <button className="button ghost" disabled={current >= last} onClick={() => onChange(current + 1)}>Próxima</button>
  </div></div>;
}

function Status({ active }: { active: boolean }) {
  return <span className={'state-pill ' + (active ? 'online' : 'neutral')}>{active ? 'Ativo' : 'Inativo'}</span>;
}

export function CustomerOrganizationPage({ token, canWrite }: { token: string; canWrite: boolean }) {
  const params = useParams();
  const customerId = Number(params.id);
  const valid = Number.isSafeInteger(customerId) && customerId > 0;
  const qc = useQueryClient();
  const [section, setSection] = useState<'locations' | 'centers'>('locations');
  const [locationPage, setLocationPage] = useState(1);
  const [departmentPage, setDepartmentPage] = useState(1);
  const [centerPage, setCenterPage] = useState(1);
  const [locationId, setLocationId] = useState<number | null>(null);
  const [editing, setEditing] = useState<Editor | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [activeOnly, setActiveOnly] = useState(true);
  const base = '/customers/' + customerId;
  const locationsPath = base + '/locations';
  const centersPath = base + '/cost-centers';
  const departmentPath = locationsPath + '/' + locationId + '/departments';

  const customer = useQuery({
    queryKey: ['customer-detail', customerId],
    enabled: valid,
    queryFn: () => api<{ data: Customer }>(base, token),
  });
  const locations = useQuery({
    queryKey: ['organization-locations', customerId, locationPage, activeOnly],
    enabled: valid && section === 'locations',
    queryFn: () => api<Page<Location>>(locationsPath + queryString({ page: locationPage, active_only: activeOnly }), token),
    placeholderData: keepPreviousData,
  });
  const departments = useQuery({
    queryKey: ['organization-departments', customerId, locationId, departmentPage, activeOnly],
    enabled: valid && section === 'locations' && locationId !== null,
    queryFn: () => api<Page<Department>>(departmentPath + queryString({ page: departmentPage, active_only: activeOnly }), token),
    placeholderData: keepPreviousData,
  });
  const centers = useQuery({
    queryKey: ['organization-centers', customerId, centerPage, activeOnly],
    enabled: valid && section === 'centers',
    queryFn: () => api<Page<CostCenter>>(centersPath + queryString({ page: centerPage, active_only: activeOnly }), token),
    placeholderData: keepPreviousData,
  });

  async function refresh() {
    await qc.invalidateQueries({ queryKey: ['organization-locations', customerId] });
    await qc.invalidateQueries({ queryKey: ['organization-departments', customerId] });
    await qc.invalidateQueries({ queryKey: ['organization-centers', customerId] });
  }

  const save = useMutation({
    mutationFn: async (payload: Record<string, string | boolean | null>) => {
      if (!editing) throw new Error('Nenhum formulário selecionado.');
      const path = editing.base + (editing.record ? '/' + editing.record.id : '');
      return api<unknown>(path, token, {
        method: editing.record ? 'PATCH' : 'POST', body: JSON.stringify(payload),
      });
    },
    onSuccess: async () => {
      setEditing(null); setNotice('Registro salvo com sucesso.'); await refresh();
    },
  });
  const deactivate = useMutation({
    mutationFn: ({ endpoint }: { endpoint: string }) => api<void>(endpoint, token, { method: 'DELETE' }),
    onSuccess: async () => {
      setNotice('Registro inativado, preservando o histórico.'); await refresh();
    },
  });

  function openNew(kind: Kind, endpoint: string) {
    setNotice(null); setEditing({ kind, base: endpoint, record: null });
  }
  function openEdit(kind: Kind, endpoint: string, record: RecordItem) {
    setNotice(null); setEditing({ kind, base: endpoint, record });
  }
  function remove(kind: Kind, endpoint: string, record: RecordItem) {
    if (window.confirm('Inativar ' + labelFor(kind) + ' "' + record.name + '"? O histórico será preservado.')) {
      setNotice(null);
      deactivate.mutate({ endpoint: endpoint + '/' + record.id });
    }
  }

  if (!valid) return <p role="alert" className="notice error">Identificador do cliente inválido.</p>;
  if (customer.isPending) return <div className="loading">Carregando cliente...</div>;
  if (customer.error) return <p role="alert" className="notice error">{readableError(customer.error)}</p>;
  const client = customer.data.data;
  const writable = canWrite && client.active;

  return <div className="organization-page">
    <Link to="/clientes" className="back-link"><ArrowLeft size={16}/> Voltar aos clientes</Link>
    <div className="page-heading">
      <div><span className="eyebrow">ESTRUTURA ORGANIZACIONAL</span><h1>{client.name}</h1>
        <p>Filiais, departamentos e centros de custo registrados para este cliente.</p>
      </div>
      <Status active={client.active}/>
    </div>
    {!client.active && <div className="notice error" role="status">Cliente inativo: novas unidades e vínculos estão bloqueados.</div>}
    <nav aria-label="Estrutura do cliente" className="organization-tabs">
      <button type="button" className={'organization-tab' + (section === 'locations' ? ' selected' : '')}
        onClick={() => setSection('locations')}><MapPin size={18}/> Unidades e departamentos</button>
      <button type="button" className={'organization-tab' + (section === 'centers' ? ' selected' : '')}
        onClick={() => setSection('centers')}><Building2 size={18}/> Centros de custo</button>
    </nav>
    <div className="table-tools organization-tools">
      <label className="check-row"><input type="checkbox" checked={activeOnly} onChange={e => {
        setActiveOnly(e.target.checked); setLocationPage(1); setDepartmentPage(1); setCenterPage(1);
      }}/> Somente ativos</label>
      {writable && <button className="button primary" onClick={() => openNew(
        section === 'locations' ? 'location' : 'center',
        section === 'locations' ? locationsPath : centersPath,
      )}><Plus size={17}/> {section === 'locations' ? 'Nova unidade' : 'Novo centro de custo'}</button>}
    </div>
    {notice && <p className="notice success" role="status">{notice}</p>}
    {deactivate.error && <p className="notice error" role="alert">{readableError(deactivate.error)}</p>}
    {section === 'locations' ? <>
      <section className="data-card">
        <h2 className="organization-section-title"><Building2 size={18}/> Unidades cadastradas</h2>
        {locations.isPending ? <div className="loading">Carregando unidades...</div> :
          locations.error ? <p role="alert" className="notice error">{readableError(locations.error)}</p> :
          !locations.data.data.length ? <div className="empty-state">Nenhuma unidade encontrada.</div> : <>
          <div className="table-wrap"><table><thead><tr><th>Unidade</th><th>Cidade / UF</th><th>Departamentos</th><th>Situação</th><th>Ações</th></tr></thead>
          <tbody>{locations.data.data.map(loc => <tr key={loc.id}>
            <td><strong>{loc.name}</strong><small>{loc.code ?? 'Sem código'} {loc.document ? '• ' + loc.document : ''}</small></td>
            <td>{[loc.city, loc.state].filter(Boolean).join(' / ') || '—'}</td>
            <td>{loc.departments_count ?? 0}</td><td><Status active={loc.active}/></td>
            <td><div className="table-actions">
              <button className="button ghost" type="button" onClick={() => {
                setLocationId(loc.id); setDepartmentPage(1);
              }}><Users size={15}/> Departamentos</button>
              {canWrite && <button className="icon-button" title="Editar unidade" aria-label={'Editar ' + loc.name}
                onClick={() => openEdit('location', locationsPath, loc)}><Pencil size={16}/></button>}
              {canWrite && loc.active && <button className="icon-button danger-icon" title="Inativar unidade"
                aria-label={'Inativar ' + loc.name} disabled={deactivate.isPending}
                onClick={() => remove('location', locationsPath, loc)}><Trash2 size={16}/></button>}
            </div></td>
          </tr>)}</tbody></table></div>
          <Pagination value={locations.data as Page<RecordItem>} onChange={setLocationPage}/>
        </>}
      </section>
      {locationId !== null && <section className="data-card organization-subsection">
        <div className="organization-subsection-header">
          <h2 className="organization-section-title"><Users size={18}/> Departamentos da unidade #{locationId}</h2>
          <div className="inline">
            {writable && <button className="button primary" type="button" onClick={() => openNew('department', departmentPath)}>
              <Plus size={16}/> Novo departamento</button>}
            <button type="button" className="icon-button" aria-label="Fechar departamentos" onClick={() => setLocationId(null)}><X size={17}/></button>
          </div>
        </div>
        {departments.isPending ? <div className="loading">Carregando departamentos...</div> :
          departments.error ? <p role="alert" className="notice error">{readableError(departments.error)}</p> :
          !departments.data.data.length ? <div className="empty-state">Nenhum departamento nesta unidade.</div> : <>
          <div className="table-wrap"><table><thead><tr><th>Departamento</th><th>Responsável</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
            {departments.data.data.map(dept => <tr key={dept.id}>
              <td><strong>{dept.name}</strong><small>{dept.code ?? 'Sem código'}</small></td>
              <td>{dept.responsible_name ?? dept.responsible_email ?? '—'}</td>
              <td><Status active={dept.active}/></td>
              <td><div className="table-actions">
                {canWrite && <button className="icon-button" title="Editar departamento" aria-label={'Editar ' + dept.name}
                  onClick={() => openEdit('department', departmentPath, dept)}><Pencil size={16}/></button>}
                {canWrite && dept.active && <button className="icon-button danger-icon" title="Inativar departamento"
                  aria-label={'Inativar ' + dept.name} disabled={deactivate.isPending}
                  onClick={() => remove('department', departmentPath, dept)}><Trash2 size={16}/></button>}
              </div></td>
            </tr>)}
          </tbody></table></div>
          <Pagination value={departments.data as Page<RecordItem>} onChange={setDepartmentPage}/>
        </>}
      </section>}
    </> : <section className="data-card">
      <h2 className="organization-section-title"><Building2 size={18}/> Centros de custo</h2>
      {centers.isPending ? <div className="loading">Carregando centros de custo...</div> :
        centers.error ? <p role="alert" className="notice error">{readableError(centers.error)}</p> :
        !centers.data.data.length ? <div className="empty-state">Nenhum centro de custo encontrado.</div> : <>
        <div className="table-wrap"><table><thead><tr><th>Código</th><th>Nome</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
          {centers.data.data.map(center => <tr key={center.id}>
            <td><strong>{center.code}</strong></td><td>{center.name}</td><td><Status active={center.active}/></td>
            <td><div className="table-actions">
              {canWrite && <button className="icon-button" title="Editar centro de custo" aria-label={'Editar ' + center.name}
                onClick={() => openEdit('center', centersPath, center)}><Pencil size={16}/></button>}
              {canWrite && center.active && <button className="icon-button danger-icon" title="Inativar centro de custo"
                aria-label={'Inativar ' + center.name} disabled={deactivate.isPending}
                onClick={() => remove('center', centersPath, center)}><Trash2 size={16}/></button>}
            </div></td>
          </tr>)}
        </tbody></table></div>
        <Pagination value={centers.data as Page<RecordItem>} onChange={setCenterPage}/>
      </>}
    </section>}
    {editing && <EditorDialog key={editing.kind + '-' + (editing.record?.id ?? 'new')} editing={editing}
      pending={save.isPending} onCancel={() => setEditing(null)} onSubmit={async payload => { await save.mutateAsync(payload); }} />}
  </div>;
}
