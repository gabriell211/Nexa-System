import React, { useCallback, useMemo } from 'react';
import { Alert, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '../../src/api';
import { useAuth } from '../../src/auth';
import { BrandIdentity } from '../../src/brand/BrandIdentity';
import { color } from '../../src/brand/theme';
import type { Ticket } from '../../src/types';
import { Button, Busy, Heading, Notice, TicketState, ui } from '../../src/ui';

export default function TicketListScreen(): React.JSX.Element {
  const { user, signOut } = useAuth();
  const client = useQueryClient();
  const query = useInfiniteQuery({
    queryKey: ['technician-tickets', user?.id],
    queryFn: ({ pageParam }) => api.tickets(pageParam),
    initialPageParam: 1,
    getNextPageParam: page => {
      const current = page.meta?.current_page ?? 1;
      const last = page.meta?.last_page ?? 1;
      return current < last ? current + 1 : undefined;
    },
  });
  const tickets = useMemo(() => {
    const unique = new Map<number, Ticket>();
    for (const page of query.data?.pages ?? []) {
      for (const item of page.data) unique.set(item.id, item);
    }
    return [...unique.values()];
  }, [query.data]);

  useFocusEffect(useCallback(() => {
    void client.invalidateQueries({ queryKey: ['technician-tickets', user?.id] });
  }, [client, user?.id]));

  function confirmLogout(): void {
    Alert.alert('Sair do Nexa?', 'A sessão será removida deste aparelho.', [
      { text: 'Cancelar', style: 'cancel' },
      { text: 'Sair', style: 'destructive', onPress: () => {
        void signOut().catch(() => undefined);
      } },
    ]);
  }

  return <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
    <View style={styles.header}>
      <BrandIdentity compact />
      <Pressable accessibilityRole="button" onPress={confirmLogout} style={styles.logout}>
        <Text style={ui.link}>Sair</Text>
      </Pressable>
    </View>
    <View style={styles.heading}>
      <View style={{ flex: 1 }}>
        <Heading eyebrow="PAINEL DO TÉCNICO" title="Meus chamados"
          subtitle={user ? 'Bem-vindo, ' + user.name + '. Aqui estão somente suas OS atribuídas.' :
            'Atendimentos atribuídos a você.'} />
      </View>
      <View style={styles.counter}>
        <Text style={styles.counterNumber}>{tickets.length}</Text>
        <Text style={styles.counterText}>carregados</Text>
      </View>
    </View>
    {query.isPending
      ? <Busy label="Consultando suas OS no Nexa…" />
      : <FlatList data={tickets} keyExtractor={item => String(item.id)}
        contentContainerStyle={styles.list}
        onRefresh={() => void query.refetch()}
        refreshing={query.isRefetching && !query.isFetchingNextPage}
        onEndReached={() => {
          if (query.hasNextPage && !query.isFetchingNextPage) void query.fetchNextPage();
        }}
        onEndReachedThreshold={0.35}
        ListHeaderComponent={<>
          {query.isError && <Notice error text={query.error instanceof Error
            ? query.error.message : 'Não foi possível carregar as OS.'} />}
        </>}
        ListFooterComponent={query.isFetchingNextPage ? <Busy label="Carregando mais OS…" /> : null}
        ListEmptyComponent={!query.isError ? <View style={ui.card}>
          <Text style={ui.section}>Nenhum chamado atribuído</Text>
          <Text style={ui.muted}>Quando o administrativo atribuir uma OS, ela aparecerá aqui.</Text>
        </View> : <Button title="Tentar novamente" onPress={() => void query.refetch()} />}
        renderItem={({ item }) => <Pressable style={ui.card} accessibilityRole="button"
          accessibilityLabel={'Abrir OS ' + item.id + ', ' + item.subject}
          onPress={() => router.push({ pathname: '/ticket/[id]', params: { id: String(item.id) } })}>
          <View style={ui.row}>
            <Text style={styles.kicker}>OS #{item.id}</Text>
            <TicketState status={item.status} />
          </View>
          <Text style={ui.section}>{item.subject}</Text>
          <Text style={ui.muted}>{item.customer?.name || 'Atendimento interno'}</Text>
          <View style={ui.row}>
            <Text style={ui.muted}>Prioridade: {item.priority}</Text>
            <Text style={ui.link}>Abrir atendimento ›</Text>
          </View>
        </Pressable>} />}
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: color.bg },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center',
    paddingHorizontal: 18, paddingVertical: 12, borderBottomWidth: 1,
    borderBottomColor: color.line },
  logout: { padding: 12 },
  heading: { flexDirection: 'row', paddingHorizontal: 18, gap: 14, alignItems: 'center' },
  counter: { backgroundColor: color.navy, padding: 12, borderRadius: 16, minWidth: 76,
    alignItems: 'center' },
  counterNumber: { color: '#fff', fontWeight: '900', fontSize: 25 },
  counterText: { color: '#CDE5FF', fontSize: 10 },
  list: { padding: 18, paddingBottom: 48, flexGrow: 1 },
  kicker: { color: color.brandDark, fontSize: 12, fontWeight: '800' },
});
