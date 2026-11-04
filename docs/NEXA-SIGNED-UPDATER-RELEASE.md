# Nexa Collector: atualização de instaladores assinados

## Distribuição

Console: Tauri 2 Updater, Minisign e verificação da versão assinada.
API: GET /api/v1/collector/updates/{target}/{arch}/{currentVersion}.
Sem feed assinado configurado no Laravel, a resposta é HTTP 204.
Build sem chave pública e NEXA_SIGNED_UPDATER_ENABLED=1 não instala pacotes remotos.

Plataformas: Windows x86_64 NSIS; Linux x86_64 DEB/RPM; macOS Intel/ARM64 PKG.
Windows: assinatura Tauri, hooks SCM e cópia do daemon anterior.
Linux: assinatura Tauri, pkexec+dpkg/rpm, snapshot privado e systemd.
macOS: assinatura Tauri MAIS Apple Developer ID Installer via Gatekeeper; pkgbuild,
snapshot privilegiado e launchd. Notarização/codesign Apple é exigida na publicação.

## Segredos obrigatórios de distribuição

Gerar chave persistente uma vez via Tauri CLI signer generate.
Guardar TAURI_SIGNING_PRIVATE_KEY e sua senha em CI secrets protegidos.
Incorporar NEXA_TAURI_PUBLIC_KEY nos builds via
python3 scripts/prepare_collector_signed_updater.py.
Nunca versionar a chave privada, nem gerar outra em cada publicação.
macOS precisa também de identidade/certificados Developer ID Application/Installer.

## Preparação de release

1. Compilar instaladores com chave pública e assinador privado verificados.
2. Gerar arquivo .sig para cada pacote FINAL com npm run tauri signer sign -- arquivo.
3. Publicar os pacotes imutáveis de uma nova versão e suas assinaturas.
4. Executar: python3 scripts/build_nexa_update_manifest.py --version 0.1.7
   --assets release --base-url https://github.com/gabriell211/Nexa-System/releases/download/collector-v0.1.7
5. Inserir o JSON resultante em NEXA_COLLECTOR_UPDATE_MANIFEST_JSON na Vercel
   somente depois de confirmar os cinco links e respectivas assinaturas.

## Testes de homologação

Testar corrupção de assinatura/versão, perda de rede, update interrompido, rollback
em Windows SCM, Linux systemd, Mac launchd, versões 0.1.6->0.1.7, e persistência
de config.json, credenciais, queue.sqlite, queue.sqlite-wal e queue.sqlite-shm.
Builds existentes sem chaves permanentes continuam sem auto-instalação.
Até todos os ensaios em hardware real passarem, não habilitar o feed em produção.
