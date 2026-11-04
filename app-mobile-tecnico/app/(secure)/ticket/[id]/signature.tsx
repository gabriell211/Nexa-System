import React, { useRef, useState } from 'react';
import { Alert, StyleSheet, Switch, Text, View, type GestureResponderEvent } from 'react-native';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Svg, { Path } from 'react-native-svg';
import ViewShot from 'react-native-view-shot';
import * as api from '../../../../src/api';
import { Button, Busy, Field, Heading, Notice, Page, Panel, ui } from '../../../../src/ui';
import { color } from '../../../../src/brand/theme';

export default function SignatureScreen(): React.JSX.Element {
  const { id: raw } = useLocalSearchParams<{ id: string }>();
  const id = typeof raw === 'string' && /^[1-9]\d*$/.test(raw) ? Number(raw) : 0;
  const valid = Number.isSafeInteger(id) && id > 0;
  const queryClient = useQueryClient();
  const ticket = useQuery({
    queryKey: ['ticket', id], queryFn: () => api.ticket(id), enabled: valid,
  });
  const [summary, setSummary] = useState('');
  const [signer, setSigner] = useState('');
  const [consent, setConsent] = useState(false);
  const [strokes, setStrokes] = useState<string[]>([]);
  const [padWidth, setPadWidth] = useState(320);
  const activeStroke = useRef(-1);
  const pad = useRef<ViewShot>(null);
  const [validation, setValidation] = useState<string | null>(null);

  function point(e: GestureResponderEvent): string {
    const { locationX, locationY } = e.nativeEvent;
    return Math.round(Math.max(0, Math.min(padWidth, locationX))) + ' ' +
      Math.round(Math.max(0, Math.min(180, locationY)));
  }
  function beginStroke(e: GestureResponderEvent): void {
    activeStroke.current = strokes.length;
    setStrokes(items => [...items, 'M ' + point(e)]);
  }
  function extendStroke(e: GestureResponderEvent): void {
    const index = activeStroke.current;
    if (index < 0) return;
    setStrokes(items => items.map((stroke, pos) => pos === index
      ? stroke + ' L ' + point(e) : stroke));
  }

  const submit = useMutation({
    mutationFn: async () => {
      if (!strokes.some(path => path.includes(' L ')) || summary.trim().length < 10 ||
        signer.trim().length < 3 || !consent) {
        throw new Error('Informe o serviço, o responsável, a assinatura e o aceite.');
      }
      const signature_png_base64 = await pad.current?.capture?.();
      if (!signature_png_base64) throw new Error('Não foi possível capturar a assinatura.');
      if (signature_png_base64.length > 335_000) {
        throw new Error('A assinatura excedeu o limite de 250 KB. Limpe e assine novamente.');
      }
      return api.signTicket(id, {
        service_summary: summary.trim(), signer_name: signer.trim(),
        signature_png_base64, consent: true,
      });
    },
    onSuccess: updated => {
      queryClient.setQueryData(['ticket', id], updated);
      void queryClient.invalidateQueries({ queryKey: ['technician-tickets'] });
      Alert.alert('Atendimento concluído',
        'Assinatura salva. A OS está resolvida e aguarda encerramento administrativo.', [
          { text: 'Ver OS', onPress: () => router.replace({
            pathname: '/ticket/[id]', params: { id: String(id) },
          }) },
        ]);
    },
  });

  if (!valid) return <Page><Notice error text="Rota de assinatura inválida." /></Page>;

  return <Page>
    <Stack.Screen options={{ title: 'Assinatura · OS #' + id }} />
    <Heading eyebrow={'FINALIZAÇÃO · OS #' + id} title="Aceite do cliente"
      subtitle="Registre o serviço executado e peça ao responsável pelo equipamento que assine." />
    {ticket.isPending && <Busy label="Validando a situação do chamado…" />}
    {ticket.isError && <Notice error text="Você não tem acesso a esta OS ou ela não existe." />}
    {ticket.data && ticket.data.status !== 'in_progress' &&
      <Notice text="Para assinar, a OS deve estar em atendimento. Retorne aos detalhes." />}
    {ticket.data?.status === 'in_progress' && <>
      <Field label="Serviço realizado e diagnóstico" value={summary}
        onChangeText={setSummary} multiline
        placeholder="Registre o reparo, os testes realizados e o resultado." />
      <Field label="Nome do responsável que assina" value={signer}
        onChangeText={setSigner} placeholder="Nome completo" />
      <Text style={ui.section}>Assinatura do responsável</Text>
      <Panel>
        <ViewShot ref={pad} options={{ format: 'png', result: 'base64', quality: 1 }}>
          <View style={styles.pad} collapsable={false}
            onLayout={event => setPadWidth(Math.max(200, event.nativeEvent.layout.width))}
            onStartShouldSetResponder={() => true}
            onMoveShouldSetResponder={() => true}
            onResponderGrant={beginStroke}
            onResponderMove={extendStroke}
            onResponderRelease={() => { activeStroke.current = -1; }}
            onResponderTerminate={() => { activeStroke.current = -1; }}>
            <Svg width="100%" height={180} viewBox={'0 0 ' + padWidth + ' 180'}>
              {strokes.map((path, index) => <Path key={index} d={path} fill="none"
                stroke="#101829" strokeWidth={2.7}
                strokeLinecap="round" strokeLinejoin="round" />)}
            </Svg>
          </View>
        </ViewShot>
        <Button title="Limpar assinatura" secondary disabled={submit.isPending}
          onPress={() => { setStrokes([]); activeStroke.current = -1; }} />
      </Panel>
      <View style={styles.consent}>
        <Switch value={consent} onValueChange={setConsent}
          accessibilityLabel="Confirmar aceite do responsável" disabled={submit.isPending} />
        <Text style={[ui.text, { flex: 1 }]}>
          O responsável confirma a realização do serviço descrito e autoriza
          a vinculação desta assinatura à OS #{id}.
        </Text>
      </View>
      {validation && <Notice error text={validation} />}
      {submit.isError && <Notice error text={submit.error instanceof Error
        ? submit.error.message : 'Não foi possível salvar o aceite.'} />}
      <Button title={submit.isPending ? 'Salvando assinatura…' : 'Salvar assinatura e resolver OS'}
        disabled={submit.isPending} onPress={() => {
          setValidation(null);
          if (!strokes.some(path => path.includes(' L ')) || signer.trim().length < 3 ||
            summary.trim().length < 10 || !consent) {
            setValidation('Preencha o diagnóstico, nome, assinatura e consentimento.');
            return;
          }
          submit.mutate();
        }} />
      <Text style={ui.muted}>Assinatura eletrônica simples, com evidência
        privada, registro de data e integridade SHA-256. Não equivale
        automaticamente a um certificado ICP-Brasil.</Text>
    </>}
  </Page>;
}

const styles = StyleSheet.create({
  pad: { height: 180, overflow: 'hidden', backgroundColor: color.surface,
    borderColor: color.border, borderWidth: 1, borderRadius: 12 },
  consent: { backgroundColor: color.surface, borderRadius: 12, borderWidth: 1,
    borderColor: color.border, padding: 13, flexDirection: 'row',
    alignItems: 'center', gap: 14 },
});
