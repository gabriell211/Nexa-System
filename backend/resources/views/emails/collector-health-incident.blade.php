Nexa System — {{ $tenantName }}

@if($stage === 'escalation')
ESCALONAMENTO: o alerta de comunicação do Collector continua aberto.
@else
ALERTA: não recebemos um heartbeat recente de um Collector previamente ativo.
@endif

Cliente: {{ $customerName }}
Agente: #{{ $collectorNumber }}
Incidente: #{{ $incidentNumber }}
Último heartbeat recebido (UTC): {{ $lastHeartbeatUtc }}
Alerta detectado (UTC): {{ $detectedAtUtc }}

A ausência de heartbeat NÃO confirma que o serviço, a rede ou uma impressora estejam offline.
Verifique a página "Agentes de coleta" no Nexa System e investigue a conectividade antes de tomar medidas.

Este e-mail é uma notificação administrativa. A aceitação pelo transporte de e-mail não comprova entrega à caixa postal.
