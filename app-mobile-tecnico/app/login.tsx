import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { BrandIdentity } from '../src/brand/BrandIdentity';
import { color } from '../src/brand/theme';
import { useAuth } from '../src/auth';
import { Button, Field, Heading, Notice } from '../src/ui';

export default function LoginScreen(): React.JSX.Element {
  const { signIn, offline, retrySession, restoring } = useAuth();
  const [loginName, setLoginName] = useState('');
  const [password, setPassword] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(): Promise<void> {
    if (!loginName.trim() || !password) return;
    setPending(true);
    setError(null);
    try {
      await signIn(loginName, password);
      setPassword('');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Falha ao entrar no Nexa.');
    } finally {
      setPending(false);
    }
  }

  return <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
    <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <View style={styles.hero}>
          <BrandIdentity />
          <Heading eyebrow="CENTRAL DE SERVIÇOS EM CAMPO"
            title="Seu próximo atendimento começa aqui."
            subtitle="Acesse seus chamados, consulte peças e registre o aceite do cliente." />
        </View>
        <View style={styles.card}>
          <Text style={styles.cardTitle}>Acesso do técnico</Text>
          <Text style={styles.hint}>Informe o usuário e a senha ativados no Nexa Administrativo.</Text>
          {offline && <Notice text="Não foi possível validar a sessão anterior. Confira a rede ou entre novamente." />}
          {offline && <Button title={restoring ? 'Reconectando…' : 'Revalidar sessão'}
            disabled={restoring || pending} secondary onPress={() => {
              void retrySession().catch(() => setError('Falha ao verificar a sessão.'));
            }} />}
          <Field label="Nome de usuário" value={loginName}
            onChangeText={value => setLoginName(value.slice(0, 64))} placeholder="ex.: joao.silva" autoCapitalize="none" />
          <Field label="Senha" value={password} onChangeText={value => setPassword(value.slice(0, 1024))}
            secureTextEntry autoCapitalize="none" placeholder="Sua senha" />
          {error && <Notice error text={error} />}
          <Button title={pending ? 'Entrando…' : 'Acessar meus chamados'}
            disabled={pending || !loginName.trim() || !password} onPress={() => void submit()} />
        </View>
        <Text style={styles.footer}>NEXA SYSTEM · ATENDIMENTO TÉCNICO</Text>
      </ScrollView>
    </KeyboardAvoidingView>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: color.bg },
  flex: { flex: 1 },
  container: { flexGrow: 1, padding: 22, justifyContent: 'center', gap: 28 },
  hero: { gap: 12 },
  card: { padding: 21, backgroundColor: color.surface, borderRadius: 22,
    borderColor: color.border, borderWidth: 1, gap: 13 },
  cardTitle: { color: color.navy, fontSize: 21, fontWeight: '800' },
  hint: { color: color.muted, lineHeight: 21, fontSize: 14 },
  footer: { textAlign: 'center', fontSize: 10, fontWeight: '800', letterSpacing: 1.2,
    color: color.faint },
});
