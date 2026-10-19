import { useDeferredValue, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, queryString } from '../api';
import type { FleetPrinter, Page } from '../types';

export function useFleet() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [active, setActive] = useState(true);
  const delayed = useDeferredValue(search);
  const request = useQuery({
    queryKey: ['client-fleet', page, delayed, active],
    queryFn: () => api<Page<FleetPrinter>>(
      '/client/printers' + queryString({ page, q: delayed, active_only: active, per_page: 25 }), null,
    ),
    placeholderData: keepPreviousData,
  });
  return { page, setPage, search, setSearch, active, setActive, request };
}
