import React from 'react';
import { router } from 'expo-router';
import { Heading, Page, Notice, Button } from '../src/ui';

export default function NotFoundScreen(): React.JSX.Element {
  return <Page>
    <Heading eyebrow="NEXA TÉCNICO" title="Página não encontrada"
      subtitle="Esta rota não existe nesta versão do aplicativo." />
    <Notice text="Nenhum atendimento foi alterado. Volte para os seus chamados." />
    <Button title="Voltar ao início" onPress={() => router.replace('/')} />
  </Page>;
}
