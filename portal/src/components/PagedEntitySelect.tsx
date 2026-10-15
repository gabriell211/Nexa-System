import { useDeferredValue, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, queryString, readableError } from '../api';
import type { Page } from '../types';

type Entity = { id: number };

interface Props<T extends Entity> {
  id: string;
  label: string;
  searchLabel: string;
  placeholder: string;
  emptyLabel: string;
  value: string;
  onChange: (value: string) => void;
  queryKey: readonly unknown[];
  endpoint: string;
  filters?: Record<string, string | number | boolean>;
  optionLabel: (item: T) => string;
  required?: boolean;
  disabled?: boolean;
}

/** Keeps the chosen record available while browsing other server-side result pages. */
export function PagedEntitySelect<T extends Entity>({
  id, label, searchLabel, placeholder, emptyLabel, value, onChange,
  queryKey, endpoint, filters = {}, optionLabel,
  required = false, disabled = false,
}: Props<T>) {
  const [search, setSearch] = useState('');
  const deferredSearch = useDeferredValue(search);
  const [page, setPage] = useState(1);
  const [chosen, setChosen] = useState<T | null>(null);
  const results = useQuery({
    queryKey: [...queryKey, filters, page, deferredSearch],
    queryFn: () => api<Page<T>>(endpoint + queryString({
      ...filters, page, per_page: 25,
      ...(deferredSearch.trim() ? { q: deferredSearch.trim() } : {}),
    }), null),
    enabled: !disabled,
  });
  const items = results.data?.data ?? [];
  const selected = chosen?.id === Number(value) ? chosen : null;
  const missingSelected = selected && !items.some(item => item.id === selected.id);
  const currentPage = results.data?.meta?.current_page ?? results.data?.current_page ?? page;
  const lastPage = results.data?.meta?.last_page ?? results.data?.last_page ?? 1;

  return <div className="remote-picker">
    <label htmlFor={id}>{label}</label>
    <input type="search" aria-label={searchLabel} placeholder={placeholder} maxLength={100}
      value={search} disabled={disabled}
      onChange={event => { setSearch(event.target.value); setPage(1); }} />
    <select id={id} value={value} required={required} disabled={disabled || results.isPending || Boolean(results.error)}
      onChange={event => {
        const next = event.target.value;
        setChosen(items.find(item => item.id === Number(next)) ?? null);
        onChange(next);
      }}>
      <option value="">{emptyLabel}</option>
      {missingSelected && <option value={selected.id}>{optionLabel(selected)}</option>}
      {items.map(item => <option key={item.id} value={item.id}>{optionLabel(item)}</option>)}
    </select>
    {results.isPending && !disabled && <small role="status">Buscando opções...</small>}
    {results.error && <p role="alert" className="notice error">{readableError(results.error)}</p>}
    {results.data && items.length === 0 &&
      <small>Nenhuma opção nesta página. Ajuste a busca ou avance.</small>}
    {results.data && <div className="remote-picker-pages">
      <button type="button" className="button ghost" aria-label={`${label}: página anterior`}
        disabled={disabled || currentPage <= 1} onClick={() => setPage(currentPage - 1)}>Anterior</button>
      <small>Página {currentPage} de {lastPage}</small>
      <button type="button" className="button ghost" aria-label={`${label}: próxima página`}
        disabled={disabled || currentPage >= lastPage} onClick={() => setPage(currentPage + 1)}>Próxima</button>
    </div>}
  </div>;
}
