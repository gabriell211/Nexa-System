# Nexa Collector — APIs de coleta e distribuição de atualizações

## 1. Envio de impressoras (implementado)

- `POST /api/v1/collector/ingest`: lote de amostras SNMP/USB, token Bearer do coletor, ID do equipamento previamente autorizado, ACK individual por `sample_id`.
- `POST /api/v1/collector/heartbeat`: prova de comunicação do serviço, separada da saúde de cada impressora.
- `POST /api/v1/collector/identity`: identificação e inventário do agente.
- Sem conexão: o daemon conserva a fila SQLite e reenvia com idempotência. Nenhuma senha SNMP deve ser enviada ao backend.

## 2. API de descoberta de atualização (adicionada)

`GET /api/v1/collector/updates/{target}/{arch}/{currentVersion}`

- Plataforma/versão inválida: HTTP 422.
- Sem release assinada ou sem atualização disponível: HTTP 204 (nunca sugere instalador falso).
- Com release válida para o sistema: HTTP 200 e JSON `{version,url,signature,notes?}` de protocolo Tauri v2.
- Feed configurado incorretamente: HTTP 503, sem divulgar o conteúdo da variável.
- HTTPS obrigatório para o download; nunca envie segredo de autenticação de clientes por este endpoint.
- Limitação: a disponibilidade do feed **não significa que o app já aplica atualizações**.

A fonte é `NEXA_COLLECTOR_UPDATE_MANIFEST_JSON` no backend Laravel (não contém chave privada), contendo:
```json
{
  "version": "0.1.7",
  "notes": "Descrição das alterações",
  "platforms": {
    "windows-x86_64": {"url": "https://<local-real-do-artefato-assinado>", "signature": "<conteúdo da assinatura minisign>"},
    "linux-x86_64": {"url": "https://<local-real-do-artefato-assinado>", "signature": "<conteúdo da assinatura minisign>"},
    "darwin-aarch64": {"url": "https://<local-real-do-artefato-assinado>", "signature": "<conteúdo da assinatura minisign>"}
  }
}
```

O exemplo **não** constitui uma release válida. O backend apenas entrega metadados; a verificação criptográfica obrigatória deve ocorrer no cliente instalado.

## 3. Critérios obrigatórios para habilitar instalação automática

1. Gerar e proteger a chave **privada** de assinatura no serviço de build; embutir somente a chave **pública** no instalador.
2. Produzir artefatos de atualização assinados pelo Tauri e publicá-los em canal HTTPS estável; metadados devem corresponder a hash, arquitetura e versão reais.
3. Integrar o `tauri-plugin-updater` ao Console e validar atualização em Windows/Linux/macOS com rollback, perda de rede e reinicialização.
4. O **serviço** instalado roda independentemente do Console. Fazer atualização coordenada do daemon com o gerenciador do sistema (SCM no Windows, systemd no Linux, launchd no macOS), preservando `config.json` e SQLite de coleta. Não atualizar serviço privilegiado por JavaScript nem instalar binário não assinado.
5. Homologar o processo em máquinas reais e manter a versão antiga ativa se assinatura, download ou instalação falhar.

Enquanto os passos acima não forem comprovados, a API responde HTTP 204 por padrão e nenhuma atualização remota é instalada.
