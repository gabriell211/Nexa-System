import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, queryString, readableError } from '../api';
import type { Page } from '../types';

interface Supplier {
  id: number;
  code: string;
  name: string;
  email: string | null;
  document: string | null;
  active: boolean;
}

type SupplierState = 'all' | 'active' | 'inactive';

export function StockSuppliersPanel({ token }: { token: string | null }) {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [state, setState] = useState<SupplierState>('all');
  const [page, setPage] = useState(1);
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [document, setDocument] = useState('');
  const [editing, setEditing] = useState<Supplier | null>(null);
  const [notice, setNotice] = useState('');

  const suppliers = useQuery({
    queryKey: ['stock-suppliers', page, search, state],
    queryFn: () => {
      const params: Record<string, string | number | boolean> = { page, per_page: 25 };
      if (search.trim()) params.q = search.trim();
      if (state !== 'all') params.active = state === 'active' ? 1 : 0;
      return api<Page<Supplier>>('/stock/suppliers' + queryString(params), token);
    },
  });

  const create = useMutation({
    mutationFn: () => api<{ data: Supplier }>('/stock/suppliers', token, {
      method: 'POST',
      body: JSON.stringify({
        code: code.trim(), name: name.trim(),
        email: email.trim() || null, document: document.trim() || null,
      }),
    }),
    onSuccess: async () => {
      setCode(''); setName(''); setEmail(''); setDocument('');
      setNotice('Fornecedor cadastrado e auditado.');
      await queryClient.invalidateQueries({ queryKey: ['stock-suppliers'] });
    },
  });

  const update = useMutation({
    mutationFn: (record: Supplier) => api<{ data: Supplier }>('/stock/suppliers/' + record.id, token, {
      method: 'PATCH',
      body: JSON.stringify({
        name: record.name.trim(),
        email: record.email?.trim() || null,
        document: record.document?.trim() || null,
        active: record.active,
      }),
    }),
    onSuccess: async () => {
      setEditing(null);
      setNotice('Fornecedor atualizado e auditado.');
      await queryClient.invalidateQueries({ queryKey: ['stock-suppliers'] });
    },
  });

  const currentPage = suppliers.data?.current_page ?? suppliers.data?.meta?.current_page ?? page;
  const lastPage = suppliers.data?.last_page ?? suppliers.data?.meta?.last_page ?? 1;
  const total = suppliers.data?.total ?? suppliers.data?.meta?.total ?? 0;

  const onCreate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!create.isPending) create.mutate();
  };
  const onUpdate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (editing !== null && !update.isPending) update.mutate(editing);
  };

  return <section aria-label="Gestão de fornecedores">
    <div className="data-card stock-listing">
      <h2>Fornecedores de suprimentos</h2>
      <p className="stock-hint">
        Cadastro vinculado à empresa. Desativar bloqueia novos recebimentos documentados,
        mas preserva notas fiscais, lotes e movimentações já registrados.
      </p>
      <div className="stock-form-grid">
        <div className="stock-field">
          <label htmlFor="supplier-search">Pesquisar fornecedores</label>
          <input id="supplier-search" type="search" maxLength={100} placeholder="Nome ou código"
            value={search} onChange={event => { setSearch(event.target.value); setPage(1); }} />
        </div>
        <div className="stock-field">
          <label htmlFor="supplier-active-filter">Filtrar situação</label>
          <select id="supplier-active-filter" value={state}
            onChange={event => { setState(event.target.value as SupplierState); setPage(1); }}>
            <option value="all">Todos</option>
            <option value="active">Ativos</option>
            <option value="inactive">Inativos</option>
          </select>
        </div>
      </div>
      {suppliers.isPending ? <p role="status">Carregando fornecedores...</p> :
        suppliers.error ? <p role="alert" className="notice error">{readableError(suppliers.error)}</p> :
        suppliers.data.data.length === 0 ? <p>Nenhum fornecedor encontrado.</p> :
        <div className="table-wrap">
          <table>
            <thead><tr><th>Código</th><th>Fornecedor</th><th>E-mail</th><th>Situação</th><th>Ações</th></tr></thead>
            <tbody>{suppliers.data.data.map(supplier => <tr key={supplier.id}>
              <td><strong>{supplier.code}</strong></td>
              <td>{supplier.name}</td>
              <td>{supplier.email || '—'}</td>
              <td>{supplier.active ? 'Ativo' : 'Inativo'}</td>
              <td><button type="button" className="button ghost"
                disabled={update.isPending}
                aria-label={'Editar ' + supplier.code}
                onClick={() => { setEditing({ ...supplier }); setNotice(''); }}>
                Editar
              </button></td>
            </tr>)}</tbody>
          </table>
        </div>}
      {!suppliers.isPending && !suppliers.error && <div className="pagination">
        <span>Página {currentPage} de {lastPage} · {total} fornecedores</span>
        <div className="inline">
          <button type="button" className="button ghost" disabled={currentPage <= 1}
            onClick={() => setPage(currentPage - 1)}>Anterior</button>
          <button type="button" className="button ghost" disabled={currentPage >= lastPage}
            onClick={() => setPage(currentPage + 1)}>Próxima</button>
        </div>
      </div>}
    </div>

    {notice && <p role="status" className="notice success">{notice}</p>}

    <div className="stock-forms">
      <form className="stock-form" onSubmit={onCreate}>
        <h3>Cadastrar fornecedor</h3>
        <div className="stock-form-grid">
          <div className="stock-field">
            <label htmlFor="supplier-code">Código do fornecedor *</label>
            <input id="supplier-code" required maxLength={60} value={code}
              disabled={create.isPending} onChange={event => setCode(event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="supplier-name">Nome do fornecedor *</label>
            <input id="supplier-name" required minLength={2} maxLength={180} value={name}
              disabled={create.isPending} onChange={event => setName(event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="supplier-email">E-mail do fornecedor</label>
            <input id="supplier-email" type="email" maxLength={180} value={email}
              disabled={create.isPending} onChange={event => setEmail(event.target.value)} />
          </div>
          <div className="stock-field">
            <label htmlFor="supplier-document">Documento do fornecedor</label>
            <input id="supplier-document" maxLength={30} value={document}
              disabled={create.isPending} onChange={event => setDocument(event.target.value)} />
          </div>
        </div>
        {create.error && <p role="alert" className="notice error">{readableError(create.error)}</p>}
        <button type="submit" className="button primary"
          disabled={create.isPending || !code.trim() || name.trim().length < 2}>
          {create.isPending ? 'Cadastrando...' : 'Cadastrar fornecedor'}
        </button>
      </form>

      {editing !== null && <form className="stock-form" onSubmit={onUpdate}>
        <h3>Editar fornecedor · {editing.code}</h3>
        <p className="stock-hint">O código é imutável para preservar referências de documentos.</p>
        <div className="stock-form-grid">
          <div className="stock-field">
            <label htmlFor="supplier-edit-name">Nome do fornecedor (edição) *</label>
            <input id="supplier-edit-name" required minLength={2} maxLength={180}
              value={editing.name} disabled={update.isPending}
              onChange={event => setEditing(current => current ? { ...current, name: event.target.value } : null)} />
          </div>
          <div className="stock-field">
            <label htmlFor="supplier-edit-email">E-mail (edição)</label>
            <input id="supplier-edit-email" type="email" maxLength={180}
              value={editing.email ?? ''} disabled={update.isPending}
              onChange={event => setEditing(current => current ? { ...current, email: event.target.value } : null)} />
          </div>
          <div className="stock-field">
            <label htmlFor="supplier-edit-document">Documento (edição)</label>
            <input id="supplier-edit-document" maxLength={30} value={editing.document ?? ''}
              disabled={update.isPending}
              onChange={event => setEditing(current => current ? { ...current, document: event.target.value } : null)} />
          </div>
          <div className="stock-field">
            <label htmlFor="supplier-edit-active">Fornecedor ativo</label>
            <input id="supplier-edit-active" type="checkbox" checked={editing.active}
              disabled={update.isPending}
              onChange={event => setEditing(current => current ? { ...current, active: event.target.checked } : null)} />
          </div>
        </div>
        {update.error && <p role="alert" className="notice error">{readableError(update.error)}</p>}
        <div className="inline">
          <button className="button primary" type="submit"
            disabled={update.isPending || editing.name.trim().length < 2}>
            {update.isPending ? 'Salvando...' : 'Salvar fornecedor'}
          </button>
          <button className="button ghost" type="button" disabled={update.isPending}
            onClick={() => setEditing(null)}>Cancelar edição</button>
        </div>
      </form>}
    </div>
  </section>;
}
