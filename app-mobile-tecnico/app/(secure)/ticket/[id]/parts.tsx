import React, { useRef, useState } from 'react';
import { Alert, Pressable, Text, View } from 'react-native';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import * as api from '../../../../src/api';
import type { StockItem } from '../../../../src/types';
import { Button, Busy, Field, Heading, Notice, Page, Panel, ui } from '../../../../src/ui';
import { color } from '../../../../src/brand/theme';

export default function RequestPartScreen(): React.JSX.Element {
  const { id: raw } = useLocalSearchParams<{ id: string }>();
  const id = typeof raw === 'string' && /^[1-9]\d*$/.test(raw) ? Number(raw) : 0;
  const valid = Number.isSafeInteger(id) && id > 0;
  const client = useQueryClient();
  const [search, setSearch] = useState('');
  const [term, setTerm] = useState('');
  const [selected, setSelected] = useState<StockItem | null>(null);
  const [quantity, setQuantity] = useState('1');
  const [reason, setReason] = useState('');
  const [inputError, setInputError] = useState<string | null>(null);
  const requestId = useRef<string | null>(null);
  const ticket = useQuery({
    queryKey: ['ticket', id], queryFn: () => api.ticket(id), enabled: valid,
  });
  const stock = useQuery({
    queryKey: ['stock-items', term], queryFn: () => api.stockItems(term), enabled: valid,
  });
  const submit = useMutation({
    mutationFn: async () => {
      const amount = Number(quantity);
      if (!Number.isInteger(amount) || amount < 1 || amount > 1000) {
        throw new Error('Informe uma quantidade inteira entre 1 e 1000.');
      }
      if (!selected || reason.trim().length < 5 || reason.trim().length > 1000) {
        throw new Error('Selecione uma peça e explique o motivo da solicitação.');
      }
      if (!requestId.current) requestId.current = Crypto.randomUUID();
      await api.requestPart(id, {
        stock_item_id: selected.id, quantity: amount,
        reason: reason.trim(), request_id: requestId.current,
      });
    },
    onSuccess: () => {
      requestId.current = null;
      void client.invalidateQueries({ queryKey: ['ticket', id] });
      Alert.alert('Peça solicitada', 'O administrativo receberá sua solicitação para análise.', [
        { text: 'OK', onPress: () => router.back() },
      ]);
    },
  });

  function resetId(): void { requestId.current = null; setInputError(null); }

  if (!valid) return <Page><Notice error text="Rota de OS inválida." /></Page>;
  return <Page>
    <Stack.Screen options={{ title: 'Solicitar peça · OS #' + id }} />
    <Heading eyebrow={'ESTOQUE · OS #' + id} title="Solicitação de peças"
      subtitle="Selecione a peça e descreva a necessidade para aprovação administrativa." />
    {ticket.isPending && <Busy label="Validando acesso à OS…" />}
    {ticket.isError && <Notice error text="Esta OS não está disponível para você." />}
    {ticket.data && ['closed', 'resolved'].includes(ticket.data.status) &&
      <Notice text="Esta OS já foi finalizada e não aceita novas solicitações." />}
    {ticket.data && !['closed', 'resolved'].includes(ticket.data.status) && <>
      <Field label="Pesquisar por nome ou SKU" value={search} onChangeText={value => setSearch(value.slice(0, 80))}
        placeholder="Ex.: toner, fusor, rolete" />
      <Button secondary title="Pesquisar estoque" onPress={() => setTerm(search.trim())}
        disabled={stock.isFetching} />
      {stock.isPending && <Busy label="Carregando o catálogo…" />}
      {stock.isError && <Notice error text={stock.error instanceof Error
        ? stock.error.message : 'Falha ao consultar estoque.'} />}
      {stock.data?.length === 0 && <Notice text="Nenhuma peça encontrada nessa pesquisa." />}
      {stock.data?.map(item => <Pressable accessibilityRole="button" key={item.id}
        accessibilityLabel={'Selecionar peça ' + item.name}
        onPress={() => { setSelected(item); resetId(); }}
        style={[ui.card, selected?.id === item.id && { borderColor: color.brand, borderWidth: 2 }]}>
        <View style={ui.row}>
          <Text style={ui.section}>{item.name}</Text>
          <Text style={ui.muted}>{item.sku}</Text>
        </View>
        <Text style={ui.muted}>{selected?.id === item.id ? '✓ Selecionada' : 'Toque para selecionar'}</Text>
      </Pressable>)}
      {selected && <Panel>
        <Text style={ui.section}>Solicitar: {selected.name}</Text>
        <Field label="Quantidade" value={quantity} keyboardType="number-pad"
          onChangeText={value => { setQuantity(value); resetId(); }} />
        <Field label="Motivo da solicitação" value={reason} multiline
          onChangeText={value => { setReason(value.slice(0, 1000)); resetId(); }}
          placeholder="Descreva o defeito e por que precisa da peça." />
        {inputError && <Notice error text={inputError} />}
        {submit.isError && <Notice error text={submit.error instanceof Error
          ? submit.error.message : 'Não foi possível solicitar a peça.'} />}
        <Button title={submit.isPending ? 'Enviando…' : 'Enviar solicitação'}
          disabled={submit.isPending} onPress={() => {
            setInputError(null);
            if (!selected || reason.trim().length < 5 || reason.trim().length > 1000) {
              setInputError('Informe a peça e um motivo com pelo menos 5 caracteres.');
              return;
            }
            submit.mutate();
          }} />
        <Text style={ui.muted}>Reenvios usam o mesmo identificador para evitar solicitações duplicadas.</Text>
      </Panel>}
    </>}
  </Page>;
}
