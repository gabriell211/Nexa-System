# Nexa System — App Mobile do Técnico

> **Status:** módulo reservado para implementação. A criação desta pasta não significa que o aplicativo já esteja funcional ou homologado.

Aplicativo mobile dedicado aos técnicos de campo do Nexa System, separado do **portal administrativo**, do **portal do cliente** e do **agente coletor**.

## Escopo previsto

- Login seguro do técnico com isolamento por empresa e permissões por função.
- Lista de chamados atribuídos, prioridades, prazo SLA e histórico.
- Consulta de cliente, unidade e equipamentos (IP, número de série e status).
- Atendimento em campo: chegada, início, pausa, conclusão e evidências.
- Anexos de fotos e vídeos, com limites de tamanho e controle de acesso.
- Registro de serviços, materiais utilizados e movimentações de estoque do técnico.
- Assinatura ou aceite do cliente, quando aplicável.
- Notificações e sincronização de dados com tratamento de conectividade instável.

## Diretrizes de desenvolvimento

- **Frontend planejado:** React Native + TypeScript (avaliar Expo).
- **Backend:** usar a API existente do Nexa System; não duplicar regras de negócio no aplicativo.
- **Segurança:** autenticação individual, autorização por recurso, proteção de tokens e validação de upload no servidor.
- **Offline:** fila local protegida e operações idempotentes, sem sobrescrever dados conflitantes.
- **Qualidade:** testes unitários, de integração, E2E e homologação Android/iOS antes de considerar produção.

Os contratos de API e os fluxos serão definidos a partir dos módulos reais de chamados, estoque, clientes e equipamentos existentes no backend.

## Próximos passos

1. Levantar endpoints já disponíveis no backend e lacunas de autorização.
2. Definir navegação, tipos TypeScript e contratos da API.
3. Implementar autenticação e fluxo de chamados primeiro.
4. Adicionar anexos, materiais, sincronização offline e testes de campo.
