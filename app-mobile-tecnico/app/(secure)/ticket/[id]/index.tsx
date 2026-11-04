import React from 'react';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Text, View } from 'react-native';
import * as api from '../../../../src/api';
import { Button, Busy, Heading, Notice, Page, Panel, TicketState, ui } from '../../../../src/ui';

function ticketId(id: string | string[] | undefined): number {
  const raw = Array.isArray(id) ? id[0] : id;
  if (!raw || !/^[1-9]\d*$/.test(raw)) return 0;
  const value = Number(raw);
  return Number.isSafeInteger(value) ? value : 0;
}

export default function TicketDetailScreen(): React.JSX.Element {
  const params = useLocalSearchParams<{ id: string }>();
  const id = ticketId(params.id);
  const client = useQueryClient();
  const query = useQuery({
    queryKey: ['ticket', id], queryFn: () => api.ticket(id), enabled: id > 0,
  });
  const start = useMutation({
    mutationFn: () => api.startTicket(id),
    onSuccess: updated => {
      client.setQueryData(['ticket', id], updated);
      void client.invalidateQueries({ queryKey: ['technician-tickets'] });
    },
  });
  const record = query.data;

  if (!id) return <Page><Notice error text="Identificador da OS inválido." />
    <Button secondary title="Voltar aos chamados" onPress={() => router.replace('/')} /></Page>;

  return <Page>
    <Stack.Screen options={{ title: 'OS #' + id }} />
    {query.isPending ? <Busy label="Carregando detalhes da OS…" /> :
      query.isError ? <>
        <Notice error text={query.error instanceof api.ApiError && query.error.status === 404
          ? 'Esta OS não foi encontrada ou não está atribuída a você.'
          : query.error instanceof Error ? query.error.message : 'Erro ao consultar chamado.'} />
        <Button title="Tentar novamente" onPress={() => void query.refetch()} secondary />
        <Button title="Meus chamados" onPress={() => router.replace('/')} secondary />
      </> : record && <>
      <Heading eyebrow={'ORDEM DE SERVIÇO #' + id} title={record.subject}
        subtitle={'Prioridade: ' + record.priority} />
      <View style={ui.row}>
        <TicketState status={record.status} />
        <Button secondary title="Atualizar OS" onPress={() => void query.refetch()}
          disabled={query.isRefetching || start.isPending} />
      </View>
      <Panel>
        <Text style={ui.section}>Informações do atendimento</Text>
        <Text style={ui.muted}>Cliente</Text>
        <Text style={ui.text}>{record.customer?.name || 'Atendimento interno'}</Text>
        <Text style={ui.muted}>Equipamento</Text>
        <Text style={ui.text}>{record.printer
          ? record.printer.manufacturer + ' ' + record.printer.model : 'Não informado'}</Text>
        {record.printer?.serial_number &&
          <Text style={ui.muted}>Série: {record.printer.serial_number}</Text>}
        {record.printer?.ip_address &&
          <Text style={ui.muted}>IP: {record.printer.ip_address}</Text>}
        <Text style={ui.muted}>Descrição do problema</Text>
        <Text style={ui.text}>{record.description}</Text>
      </Panel>
      {['triage', 'unassigned', 'pending'].includes(record.status) &&
        <Button title={start.isPending ? 'Iniciando…' : 'Iniciar atendimento'}
          disabled={start.isPending} onPress={() => start.mutate()} />}
      {start.isError && <Notice error text={start.error instanceof Error
        ? start.error.message : 'Não foi possível iniciar o chamado.'} />}
      {!['resolved', 'closed'].includes(record.status) &&
        <Button title="Solicitar peça" secondary onPress={() => router.push({
          pathname: '/ticket/[id]/parts', params: { id: String(id) },
        })} />}
      <Panel>
        <Text style={ui.section}>Solicitações de peças</Text>
        {record.part_requests?.length ? record.part_requests.map(part => <View key={part.id}
          style={{ borderBottomWidth: 1, borderBottomColor: '#E9EFF7', paddingVertical: 8, gap: 4 }}>
          <Text style={ui.text}>{part.quantity} × {part.item?.name || 'Peça #' + part.stock_item_id}</Text>
          <Text style={ui.muted}>{part.status === 'approved' ? 'Aprovada' :
            part.status === 'rejected' ? 'Rejeitada' : 'Aguardando aprovação'}</Text>
        </View>) : <Text style={ui.muted}>Nenhuma peça solicitada.</Text>}
      </Panel>
      {record.status === 'in_progress' && <Button title="Registrar serviço e assinatura"
        onPress={() => router.push({
          pathname: '/ticket/[id]/signature', params: { id: String(id) },
        })} />}
      {record.signature && <Panel>
        <Text style={ui.section}>✓ Atendimento assinado</Text>
        <Text style={ui.text}>{record.signature.signer_name}</Text>
        <Text style={ui.muted}>{new Date(record.signature.signed_at).toLocaleString('pt-BR')}</Text>
        <Text style={ui.text}>{record.signature.service_summary}</Text>
        <Text style={ui.muted}>A OS resolvida aguarda encerramento administrativo.</Text>
      </Panel>}
    </>}
  </Page>;
}
