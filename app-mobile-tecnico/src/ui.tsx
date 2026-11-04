import React, { type ReactNode } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { color } from './brand/theme';
import type { Ticket } from './types';

export function Page({ children, scroll = true }: {
  children: ReactNode; scroll?: boolean;
}): React.JSX.Element {
  return <SafeAreaView edges={['bottom']} style={styles.page}>
    {scroll
      ? <ScrollView keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.scroll}>{children}</ScrollView>
      : <View style={styles.fill}>{children}</View>}
  </SafeAreaView>;
}

export function Button({ title, onPress, disabled = false, secondary = false }: {
  title: string; onPress: () => void; disabled?: boolean; secondary?: boolean;
}): React.JSX.Element {
  return <Pressable accessibilityRole="button" accessibilityLabel={title}
    accessibilityState={{ disabled }}
    onPress={onPress} disabled={disabled}
    style={[styles.button, secondary && styles.buttonSecondary, disabled && styles.disabled]}>
    <Text style={[styles.buttonLabel, secondary && styles.buttonSecondaryText]}>{title}</Text>
  </Pressable>;
}

export function Field({ label, value, onChangeText, placeholder, multiline = false,
  secureTextEntry = false, keyboardType = 'default', autoCapitalize = 'sentences' }: {
    label: string; value: string; onChangeText: (value: string) => void;
    placeholder?: string; multiline?: boolean; secureTextEntry?: boolean;
    keyboardType?: 'default' | 'number-pad' | 'email-address';
    autoCapitalize?: 'none' | 'sentences' | 'words';
  }): React.JSX.Element {
  return <View style={styles.field}>
    <Text style={styles.label}>{label}</Text>
    <TextInput accessibilityLabel={label} value={value} onChangeText={onChangeText}
      placeholder={placeholder} placeholderTextColor={color.faint}
      keyboardType={keyboardType} autoCapitalize={autoCapitalize}
      secureTextEntry={secureTextEntry} multiline={multiline}
      style={[styles.input, multiline && styles.inputMultiline]} />
  </View>;
}

export function Notice({ text, error = false }: {
  text: string; error?: boolean;
}): React.JSX.Element {
  return <View accessibilityRole={error ? 'alert' : 'text'}
    style={[styles.notice, error && styles.noticeError]}>
    <Text style={[styles.noticeText, error && styles.noticeErrorText]}>{text}</Text>
  </View>;
}

export function Busy({ label = 'Carregando…' }: { label?: string }): React.JSX.Element {
  return <View style={styles.busy}><ActivityIndicator color={color.brand} size="large" />
    <Text style={styles.muted}>{label}</Text></View>;
}
export function Heading({ eyebrow, title, subtitle }: {
  eyebrow?: string; title: string; subtitle?: string;
}): React.JSX.Element {
  return <View style={styles.heading}>
    {eyebrow && <Text style={styles.eyebrow}>{eyebrow}</Text>}
    <Text style={styles.title}>{title}</Text>
    {subtitle && <Text style={styles.muted}>{subtitle}</Text>}
  </View>;
}
export function Panel({ children }: { children: ReactNode }): React.JSX.Element {
  return <View style={styles.panel}>{children}</View>;
}

export function TicketState({ status }: { status: Ticket['status'] }): React.JSX.Element {
  const label = {
    triage: 'Triagem', unassigned: 'Sem responsável', pending: 'Pendente',
    in_progress: 'Em atendimento', resolved: 'Resolvido', closed: 'Encerrado',
  }[status] || status;
  return <View style={styles.chip}><Text style={styles.chipText}>{label}</Text></View>;
}

export const ui = StyleSheet.create({
  title: { fontSize: 28, fontWeight: '800', color: color.navy, letterSpacing: -0.6 },
  section: { fontSize: 17, fontWeight: '800', color: color.navy },
  text: { color: color.ink, fontSize: 15, lineHeight: 23 },
  muted: { color: color.muted, fontSize: 13, lineHeight: 20 },
  link: { color: color.brand, fontWeight: '800', fontSize: 14 },
  card: { backgroundColor: color.surface, borderWidth: 1, borderColor: color.border,
    padding: 17, borderRadius: 18, gap: 8, marginBottom: 10 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 10 },
});

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: color.bg },
  fill: { flex: 1, paddingHorizontal: 18 },
  scroll: { paddingHorizontal: 18, paddingTop: 12, paddingBottom: 40, gap: 14 },
  button: { minHeight: 50, backgroundColor: color.brand, padding: 14, borderRadius: 14,
    alignItems: 'center', justifyContent: 'center', marginVertical: 4 },
  buttonSecondary: { backgroundColor: color.softBlue },
  buttonLabel: { fontSize: 15, fontWeight: '800', color: color.surface },
  buttonSecondaryText: { color: color.brandDark },
  disabled: { opacity: 0.47 },
  field: { gap: 7, marginVertical: 5 },
  label: { color: color.ink, fontWeight: '700', fontSize: 13 },
  input: { backgroundColor: color.surface, borderColor: color.border,
    borderWidth: 1, borderRadius: 12, padding: 13, minHeight: 50,
    fontSize: 16, color: color.ink },
  inputMultiline: { minHeight: 110, textAlignVertical: 'top' },
  notice: { backgroundColor: color.softBlue, padding: 13, borderRadius: 12 },
  noticeError: { backgroundColor: color.dangerBg },
  noticeText: { color: color.brandDark, fontSize: 13 },
  noticeErrorText: { color: color.danger },
  busy: { flex: 1, justifyContent: 'center', alignItems: 'center', gap: 15 },
  muted: { color: color.muted, fontSize: 13, lineHeight: 21 },
  heading: { gap: 7, marginVertical: 8 },
  eyebrow: { fontSize: 11, color: color.brandDark, letterSpacing: 1.4, fontWeight: '800' },
  title: { fontSize: 28, color: color.navy, fontWeight: '900', letterSpacing: -0.6 },
  panel: { backgroundColor: color.surface, borderRadius: 18, padding: 18,
    borderWidth: 1, borderColor: color.border, gap: 12 },
  chip: { borderRadius: 99, paddingHorizontal: 12, paddingVertical: 7,
    backgroundColor: color.softBlue },
  chipText: { color: color.brandDark, fontSize: 12, fontWeight: '700' },
});
